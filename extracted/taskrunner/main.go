package main

import (
	"database/sql"
	"errors"
	"io/ioutil"
	"math"
	"os"
	"os/signal"
	"path/filepath"
	"reflect"
	"syscall"

	//"fmt"
	"container/heap"
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"strconv"
	"time"

	"github.com/go-redis/redis"
	"github.com/go-sql-driver/mysql"
	"github.com/jmoiron/sqlx"
	_ "github.com/lib/pq"
	"github.com/shirou/gopsutil/load"
	"github.com/spf13/viper"
)

var (
	DBG   = "nodebug"
	DEBUG = 0
	// DEPRECATED
	/*	MQ_HOST         = "localhost"
		MQ_USER         = "guest"
		MQ_PASS         = "guest"
		MQ_PORT         = 5672
		MQ_DEFAULT_CHAN = "hello"
	*/
	Version string
	Build   string
	Commit  string
	// settings that load on the application start
	settings    Settings
	ProgramPath string
	Log         *log.Logger
	MySQL_HOST  string
	MySQL_USER  string
	MySQL_DBAH  string
	MySQL_PASS  string
	MySQL_PORT  string
	//	MySQLDBInterface
	// Dynamic variables
	priorityQueue    PriorityQueue
	OldQueue         PriorityQueue
	OldQueueDetected bool
	mysqldb          *sqlx.DB
	redisdb          *redis.Client
	tracking_enabled bool
	DEPLOYMENT       string
	// storage settings
	STORAGE_API     string
	STORAGE_KEY     string
	TRACKINGAS_HOST string
	TRACKINGAS_DB   string
	TRACKINGAS_USER string
	TRACKINGAS_PASS string
	// internal web server for tracking links and other resources
	http_serv_port int
)

type DataDump struct {
	PriorityQueueDump PriorityQueue
	Jobs              []Job
}

const (
	MESSAGE_TYPE_LIST_UPDATE     = 1
	MESSAGE_TYPE_CAMPAIGN_UPDATE = 2
	MESSAGE_TYPE_REDIS_CLEANER   = 500
	MESSAGE_TYPE_WARMUP_ADD      = 501
	MESSAGE_TYPE_SMTP_SEND       = 502
)

func init() {
	if DBG == "debug" {
		DEBUG = 1
	}
}

func failOnError(err error, msg string) {
	if err != nil {
		log.Fatalf("%s: %s", msg, err)
	}
}

// Queue handling

// QueueItem - Is the queue object struct
type QueueItem struct {
	Type     int
	Priority int
	Queue    Message
	Index    int // The index of the item in the heap.
}

// PriorityQueue - This is priority queue type declaration
type PriorityQueue []*QueueItem

// Returns the lenght of the queue
func (pq PriorityQueue) Len() int { return len(pq) }

// Returns counts by the queues priorities
func (pq PriorityQueue) LenLevel(level int) int {
	count := 0
	for _, item := range pq {
		if item.Priority == level {
			count++
		}
	}
	return count
}

// pq.PriorityQueue - picks the lowest expiration number
func (pq PriorityQueue) Less(i, j int) bool {
	// We want Pop to give us the lowest based on expiration number as the priority
	// The lower the expiry, the higher the priority
	return pq[i].Priority < pq[j].Priority
}

// We just implement the pre-defined function in interface of heap.

// Pop - Picks up the higher item in queue
func (pq *PriorityQueue) Pop() interface{} {
	old := *pq
	n := len(old)
	item := old[n-1]
	item.Index = -1
	*pq = old[0 : n-1]
	// remove duplicates here
	if settings.AutoFreeQueue {
		pqtmp := *pq
		for i := 0; i < len(pqtmp); i++ {
			//fmt.Printf("Layout: %d\n", pqtmp[i].Index)
			pqtmp[i].Index = i
		}
		*pq = removeDuplicates2(pqtmp, *item)
	}

	return item
}

// PopLevel2 - Pop level2 queue
func PopLevel2(pq *PriorityQueue) (*QueueItem, error) {
	pqas := *pq
	var item *QueueItem
	for i := 0; i < len(pqas); i++ {
		if pqas[i].Priority == 2 {
			item = pqas[i]
			pqas[i], pqas[len(pqas)-1] = pqas[len(pqas)-1], pqas[i]
			pqas = pqas[:len(pqas)-1]
			break
		}
	}

	// remove duplicates here
	if settings.AutoFreeQueue {
		for i := 0; i < len(pqas); i++ {
			//fmt.Printf("Layout: %d\n", pqas[i].Index)
			pqas[i].Index = i
		}
		if item != nil && len(pqas) > 0 {
			pqas = removeDuplicates2(pqas, *item)
		}
	}

	priorityQueue = pqas

	if item == nil {
		err := errors.New("Queue list is empty for PopLevel2")
		return nil, err
	}

	return item, nil

}

func removeDuplicates2(elements PriorityQueue, ComparableItem QueueItem) PriorityQueue {
	var result PriorityQueue
	result = make(PriorityQueue, 0)
	encountered := map[int]bool{}
	for v := range elements {
		if encountered[elements[v].Index] == true {
			log.Printf("Item: %v seems to be already in queue\n", elements[v])
			// Do not add duplicate.
		} else {
			// check if our element is present
			if elements[v].Priority == ComparableItem.Priority && elements[v].Type == ComparableItem.Type && elements[v].Queue.Val == ComparableItem.Queue.Val {
				// Do nothing
				log.Printf("Not including in queue: %v\n", elements[v])
			} else {
				// Record this element as an encountered element.
				encountered[elements[v].Index] = true
				// Append to result slice.
				result = append(result, elements[v])
			}
		}
	}
	// Return the new slice.
	log.Printf("After cleanup we got: %d entries in the queue\n", len(result))
	return result
}

// deprecated, must be deleted, leaved for testing purposes
func removeDuplicates(elements PriorityQueue, ComparableItem QueueItem) PriorityQueue {
	var result PriorityQueue
	result = make(PriorityQueue, 0)
	for i := 0; i < len(elements); i++ {
		// Scan slice for a previous element of the same value.
		exists := false
		for v := 0; v < i; v++ {
			if elements[v].Priority == ComparableItem.Priority && elements[v].Type == ComparableItem.Type && elements[v].Queue.Val == ComparableItem.Queue.Val {
				log.Printf("DUBLICATE FOUND QUEUE ITEM FOUND: %v\n", elements[v])
				exists = true
				break
			}
		}
		// If no previous element exists, append this one.
		if !exists {
			result = append(result, elements[i])
		}
	}
	log.Printf("After cleanup we got: %d entries in the queue\n", len(result))
	return result
}

func (pq *PriorityQueue) update(item *QueueItem, typ int, priority int) {
	item.Type = typ
	item.Priority = priority
	heap.Fix(pq, item.Index)
}

// PopLevel - Picks up the specified level queue from the list
func (pq *PriorityQueue) PopLevel(level int) interface{} {
	for _, item := range *pq {
		if item.Priority == level {
			old := *pq
			n := len(old)
			item := old[n-1]
			item.Index = -1
			*pq = old[0 : n-1]
			return item
		}
	}
	return *pq
}

// func (pq *PriorityQueue) PopLevel2() interface{} {
// 	old := *pq
// 	n := len(old)
// 	item := old[n-1]
// 	item.Index = -1
// 	*pq = old[0 : n-1]
// 	return item
// }

// func (pq *PriorityQueue) PopLevel3() interface{} {
// 	old := *pq
// 	n := len(old)
// 	item := old[n-1]
// 	item.Index = -1
// 	*pq = old[0 : n-1]
// 	return item
// }

// Push - adds the item to queue
func (pq *PriorityQueue) Push(x interface{}) {
	n := len(*pq)
	item := x.(*QueueItem)
	item.Index = n
	*pq = append(*pq, item)
}

// pq.PriorityQueue.Swap - swaps the upside down the queue list
func (pq PriorityQueue) Swap(i, j int) {
	pq[i], pq[j] = pq[j], pq[i]
	pq[i].Index = i
	pq[j].Index = j
}

// deprecated
// CleanQueue - This function cleans duplicates on the queue, doing same things couple of times is no necessary, yeah? :)
func CleanQueue(prioriry, tipas int, val string) {
	//fmt.Printf("Just received the request to clean queue for priority: %d type: %d\n", prioriry, tipas)
	for _, event := range priorityQueue {
		if event.Priority == prioriry && event.Type == tipas && event.Queue.Val == val {
			log.Printf("We will clean this type of items: %v\n", event)
			toRemove := event.Index
			for i := 0; i < len(priorityQueue); i++ {
				n := priorityQueue[i]
				if n.Index == toRemove {
					log.Printf("Removing queue from slice nr: %d count: %d: toRemove: %d\n", i, len(priorityQueue), n.Index)
					priorityQueue[i], priorityQueue[len(priorityQueue)-1] = priorityQueue[len(priorityQueue)-1], priorityQueue[i]
					priorityQueue = priorityQueue[:len(priorityQueue)-1]
					i--
				}
			}
			//	heap.Init(&priorityQueue)

		}
		//	queue[i], queue[len(queue)-1] = queue[len(queue)-1], queue[i]
		//		queue = queue[:len(queue)-1]
	}

}

// func TestPriorityQueue() {
// 	listItems := []*Item{
// 		{Name: "Carrot", Expiry: 30},
// 		{Name: "Potato", Expiry: 45},
// 		{Name: "Rice", Expiry: 100},
// 		{Name: "Spinach", Expiry: 5},
// 	}
// 	priorityQueue := make(PriorityQueue, len(listItems))

// 	for i, item := range listItems {
// 		priorityQueue[i] = item
// 		priorityQueue[i].Index = i
// 	}

// 	// itemas : = []*Item
// 	// itemas.Name = "bybasas"
// 	// itemas.Expiry = 500

// 	heap.Init(&priorityQueue)

// 	itemas := &Item{
// 		Name:   "bybasas",
// 		Expiry: 500,
// 	}
// 	heap.Push(&priorityQueue, itemas)

// 	// Print the order by Priority of expiry
// 	for priorityQueue.Len() > 0 {
// 		item := heap.Pop(&priorityQueue).(*Item)
// 		fmt.Printf("Name: %s Expiry: %d\n", item.Name, item.Expiry)
// 	}
// }

// End of queue handling

// QueueHandler - Handles the main queue in the loop
func QueueHandler() {
	for {
		loadavg, _ := load.Avg()

		if priorityQueue.Len() > 0 {
			if settings.LoadLimitEnabled && loadavg.Load1 > settings.LoadLimitVal {
				log.Printf("Average system load seems to be higher than normal we will make on hold level 3 jobs on the queue!\n")
				item, err := PopLevel2(&priorityQueue)
				if err == nil {
					log.Printf("Running QUEUE Type-> %d, Priority-> %d DATA->(%v)\n", item.Type, item.Priority, item.Queue)
					switch item.Queue.Type {
					case MESSAGE_TYPE_LIST_UPDATE:
						UpdateListCache(item.Queue)
					case MESSAGE_TYPE_CAMPAIGN_UPDATE:
						UpdateCampaignCache(item.Queue)
					}
				}
			} else {
				item := heap.Pop(&priorityQueue).(*QueueItem)
				log.Printf("Running QUEUE Type-> %d, Priority-> %d DATA->(%v)\n", item.Type, item.Priority, item.Queue)
				switch item.Queue.Type {
				case MESSAGE_TYPE_LIST_UPDATE:
					UpdateListCache(item.Queue)
				case MESSAGE_TYPE_CAMPAIGN_UPDATE:
					UpdateCampaignCache(item.Queue)
				case MESSAGE_TYPE_REDIS_CLEANER:
					RedisCleanup()
					//	case MESSAGE_TYPE_WARMUP_ADD:
					//		WarmupPool(item.Queue)
				}

			}
		}
		if DEBUG > 0 {
			log.Printf("QUEUE TOTALS: Priority3-> %d, Priority2-> %d\n", priorityQueue.LenLevel(3), priorityQueue.LenLevel(2))
		}
		time.Sleep(10 * time.Second) // queue tick
	}
}

func RedisInit() {
	redisdb = redis.NewClient(&redis.Options{Addr: "localhost:6379", Password: "", DB: 0})
}

func main() {
	log.Printf("TaskRunner Version %s (%s) Build %s\n", Version, Commit, Build)
	LoadSettings()
	// licensing block
	if EnabledLicensing() {
		testcert()
	} else {
		log.Printf("Licensing volume is not enabled\n")
	}
	// end of licensing block
	RedisInit()
	// some options for startup
	testserv := flag.Bool("testserv", false, "Start http daemon for testing links")
	cleaner := flag.Bool("cleaner", false, "Start the cleaner")
	flag.IntVar(&http_serv_port, "port", default_httpd_port, "Port for internal http server")
	flag.Parse()
	if *testserv {
		log.Printf("Test internal http server mode enabled!\n")
		go TrackingInternalLoop()
		TrackingServer()
		for {

		}
		os.Exit(0)
	}
	if *cleaner {
		fmt.Printf("Starting cleaner...\n")
		RedisCleanup()
		os.Exit(0)
	}

	writePidFile("taskrunner.pid")
	WarmupJobs = make(map[string]*Job)
	LoadProgramData()
	forever := make(chan bool)

	// QUEUE HANDLER HERE
	priorityQueue = make(PriorityQueue, 0)
	if OldQueueDetected {
		priorityQueue = OldQueue
		log.Printf("Old queue detected and loaded to the memory!\n")
		OldQueue = nil
	}
	heap.Init(&priorityQueue)
	go QueueHandler()
	go RedisQueueRespond()
	// QUEUE HANDLER ENDs HERE
	// external link tracking enabled
	if tracking_enabled {
		go TrackingInternalLoop()
		go TrackingServer()
	}

	// Intercept termination signals to the application and handle them
	c := make(chan os.Signal)
	signal.Notify(c, os.Interrupt, syscall.SIGTERM)
	go func() {
		<-c
		SigTermEvent(0)
		os.Exit(1)
	}()

	// NEW SUPPORT USING REDIS CHANNELS

	go func() {
		psNewMessage := redisdb.Subscribe("taskrunner")
		for {
			msg, _ := psNewMessage.ReceiveMessage()
			go func() {
				response := Message{}
				jsonErr := json.Unmarshal([]byte(msg.Payload), &response)
				if DEBUG > 0 {
					log.Printf("Got message: %s\n", msg.Payload)
				}
				if jsonErr == nil {
					if response.Priority == 1 {
						// Instant run not checking nor load nor add it to the queue
						switch response.Type {
						case MESSAGE_TYPE_LIST_UPDATE:
							go UpdateListCache(response)
						case MESSAGE_TYPE_CAMPAIGN_UPDATE:
							go UpdateCampaignCache(response)
						case MESSAGE_TYPE_WARMUP_ADD:
							WarmupPool(response)
						case MESSAGE_TYPE_SMTP_SEND:
							req := SMTPRequest{}
							jsonErr = json.Unmarshal([]byte(response.Val), &req)
							if jsonErr != nil {
								log.Printf("Unable to construct smtp send request: %s\n", jsonErr)
							} else {
								//log.Printf("I've got object: %#v\n", req)
								SMTPSend(req)
							}
						}
					} else {
						AddToQueue(response)
					}
				} else {
					log.Printf("Unable to unmarshal request message: %s\n", jsonErr)
				}
			}()
		}
	}()

	log.Printf(" [*] Waiting for messages. To exit press CTRL+C\n")
	<-forever
}

// writePidFile - writes process identification number to a file
func writePidFile(pidFile string) error {
	pidFile = ProgramPath + "/" + pidFile
	// Read in the pid file as a slice of bytes.
	if piddata, err := ioutil.ReadFile(pidFile); err == nil {
		// Convert the file contents to an integer.
		if pid, err := strconv.Atoi(string(piddata)); err == nil {
			// Look for the pid in the process list.
			if process, err := os.FindProcess(pid); err == nil {
				// Send the process a signal zero kill.
				if err := process.Signal(syscall.Signal(0)); err == nil {
					// We only get an error if the pid isn't running, or it's not ours.
					return fmt.Errorf("pid already running: %d", pid)
				}
			}
		}
	}
	// If we get here, then the pidfile didn't exist,
	// or the pid in it doesn't belong to the user running this app.
	return ioutil.WriteFile(pidFile, []byte(fmt.Sprintf("%d", os.Getpid())), 0664)
}

// SigTermEvent - Just saves all the shit, then the program is being killed
// or just interrupted by the SIGTERM or other kill signals or crash issues burns the wheels, so it can't run anymore
func SigTermEvent(signal int) {
	if signal == 0 {
		// clean kill
		log.Printf("Kill signal received, saving the message queue...\n")
	} else {
		// program crashed
		log.Printf("We have received the information that this program is being crashed, so we need to save the data before it completely crashes!\n")
	}
	//SaveJobs()
	// list jobs and create obj with all the jobs for dump
	TmpJobs := []Job{}
	for _, jd := range WarmupJobs {
		TmpJobs = append(TmpJobs, jd.DumpOjb())
	}

	DbDump := DataDump{PriorityQueueDump: priorityQueue, Jobs: TmpJobs}
	r, err := json.Marshal(DbDump)
	if err != nil {
		log.Printf("Unable to serialize data for data dump of the program: \n", err)
		return
	}
	err = ioutil.WriteFile(ProgramPath+"/data.dump", r, 0644)
	if err != nil {
		log.Printf("CRITICAL ERROR Saving the QUEUE data to the disk!!!\n")
		return
	}

	log.Printf("Program queue data, sucessfully dumped to the disk snapshot!\n")
}

func LoadProgramData() {
	if _, err := os.Stat(ProgramPath + "/data.dump"); err == nil {
		log.Printf("Found data snapshot on the disk: %s, loading data...\n", ProgramPath+"/data.dump")
		jsonFile, err := os.Open(ProgramPath + "/data.dump")
		// if we os.Open returns an error then handle it
		if err != nil {
			log.Printf("Unable to open data dump snapshot of the queue: %s\n", err)
		}
		log.Printf("Successfully Opened data snapshot from the disk\n")
		// defer the closing of our jsonFile so that we can parse it later on
		defer jsonFile.Close()
		byteValue, _ := ioutil.ReadAll(jsonFile)
		DbDump := DataDump{}
		err = json.Unmarshal(byteValue, &DbDump)
		if err == nil {
			OldQueue = DbDump.PriorityQueueDump
			OldQueueDetected = true
			LoadJobs(DbDump.Jobs)
		} else {
			log.Printf("Unable to parse data stored in the disk as the snapshot!\n")
		}
	} else if os.IsNotExist(err) {
		log.Printf("Program data does not exist, skipping load queue data from the disk!\n")
	}
}

// ProgramPanic - Handles the program panic on the situations that are not prepared for
func ProgramPanic(err error) {
	Logas("Program panic: %s\n", err)
	SigTermEvent(1)
	panic(err)
}

// Settings - Structure for the program settings
type Settings struct {
	LoadLimitVal     float64
	LoadLimitEnabled bool
	Logging          bool
	AutoFreeQueue    bool
	Debug            bool
}

// LoadSettings - Loads the program settings data from json file
func LoadSettings() {
	dir, err := filepath.Abs(filepath.Dir(os.Args[0]))
	ProgramPath = dir
	if err != nil {
		ProgramPanic(err)
	}
	configfile := dir + "/../public_html/.env"
	log.Printf("Laravel Configuration should be in %s\n", configfile)
	// new implementation
	viper.SetConfigFile(configfile)
	viper.ReadInConfig()
	MySQL_HOST = viper.GetString("DB_HOST")
	MySQL_DBAH = viper.GetString("DB_DATABASE")
	MySQL_PORT = viper.GetString("DB_PORT")
	MySQL_USER = viper.GetString("DB_USERNAME")
	MySQL_PASS = viper.GetString("DB_PASSWORD")
	Algorithm = viper.GetStringMapString("GOSENDER_ALGORITHM")
	DEPLOYMENT = viper.GetString("APP_DEPLOYMENT")
	// storage settings
	STORAGE_API = viper.GetString("APP_STORURL")
	STORAGE_KEY = viper.GetString("APP_STORKEY")
	TRACKINGAS_HOST = viper.GetString("TASKRUNNER_TRACKINGAS_HOST")
	TRACKINGAS_DB = viper.GetString("TASKRUNNER_TRACKINGAS_DB")
	TRACKINGAS_USER = viper.GetString("TASKRUNNER_TRACKINGAS_USER")
	TRACKINGAS_PASS = viper.GetString("TASKRUNNER_TRACKINGAS_PASS")
	settings.LoadLimitVal = float64(viper.GetInt("TASKRUNNER_LOADLIMITVAL"))
	settings.LoadLimitEnabled = viper.GetBool("TASKRUNNER_LOADLIMITENABLED")
	settings.Logging = viper.GetBool("TASKRUNNER_LOGGING")
	settings.AutoFreeQueue = viper.GetBool("TASKRUNNER_AUTOFREEQUEUE")
	settings.Debug = viper.GetBool("GOSENDER_DEBUG")

	if viper.GetBool("TASKRUNNER_TRACKING") {
		tracking_enabled = true
	} else {
		tracking_enabled = false
	}

	if settings.Debug {
		DEBUG = 1
	}
	if DEBUG > 0 {
		log.Printf("ENV: %#v\n", settings)
	}
	/*	settingsfile := "settings.json"
		Pathas, err := filepath.Abs(filepath.Dir(os.Args[0]))
		if err != nil {
			ProgramPanic(err)
		}
		if DEBUG > 0 {
			fmt.Printf("Program is running from: %s directory\n", Pathas)
		}
		ProgramPath = Pathas

		jsonFile, err := os.Open(ProgramPath + "/" + settingsfile)
		// if we os.Open returns an error then handle it
		if err != nil {
			ProgramPanic(err)
		}
		if DEBUG > 0 {
			fmt.Printf("Successfully Opened %s settings file\n", settingsfile)
		}
		defer jsonFile.Close()

		byteValue, err := ioutil.ReadAll(jsonFile)
		if err != nil {
			fmt.Println(err)
		}
		json.Unmarshal(byteValue, &settings)
		if DEBUG > 0 {
			fmt.Printf("Settings loaded!\n")
		}
	*/
	if settings.Logging == true {
		var logpath = ProgramPath + "/taskrunner.log"
		var file, err1 = os.OpenFile(logpath, os.O_RDWR|os.O_CREATE|os.O_APPEND, 0666)
		if err1 != nil {
			ProgramPanic(err1)
		}
		Log = log.New(file, "", log.LstdFlags|log.Lshortfile)
		Logas("LogFile : " + logpath)
	}
	return
}

// LoadLaravelConfig - reads laravel environment file configuration to the runtime variables
/*func LoadLaravelConfig(path string) {
	configfile := ProgramPath + "/" + path
	fmt.Printf("Laravel Configuration should be in %s\n", configfile)
	cfg, err := ini.Load(configfile)
	if err != nil {
		fmt.Printf("Fail to read laravel environment configuration file: %s, error: %s", configfile, err)
	}
	MySQL_HOST = cfg.Section("").Key("DB_HOST").String()
	MySQL_DBAH = cfg.Section("").Key("DB_DATABASE").String()
	MySQL_USER = cfg.Section("").Key("DB_USERNAME").String()
	MySQL_PASS = cfg.Section("").Key("DB_PASSWORD").String()
	MySQL_PORT = cfg.Section("").Key("DB_PORT").String()
	if DEBUG > 0 {
		fmt.Printf("We have done reading laravel environment configuration file with these specs: {host:%s,db:%s,user:%s,port:%s}\n", MySQL_HOST, MySQL_DBAH, MySQL_USER, MySQL_PORT)
	}
	return
}
*/

func dbconn() {

	if mysqldb == nil {
		//	db, err := sql.Open("mysql", MySQL_USER+":"+MySQL_PASS+"@tcp("+MySQL_HOST+":"+MySQL_PORT+")/"+MySQL_DBAH)
		db, err := sqlx.Connect("mysql", fmt.Sprintf("%s:%s@tcp(%s:%s)/%s", MySQL_USER, MySQL_PASS, MySQL_HOST, MySQL_PORT, MySQL_DBAH))
		if err != nil {
			log.Printf("Got error then tried to contact to mysql server %s\n", err)
			return
		}
		mysqldb = db
		return
	}

	err := mysqldb.Ping()
	if err != nil {
		mysqldb = nil
		log.Printf("Unable to ping the MySQL connection, it's lost!! Reconnecting...\n")
		db, err := sqlx.Connect("mysql", fmt.Sprintf("%s:%s@tcp(%s:%s)/%s", MySQL_USER, MySQL_PASS, MySQL_HOST, MySQL_PORT, MySQL_DBAH))
		if err != nil {
			log.Printf("Got error then tried to contact to mysql server %s\n", err)
			return
		}
		log.Printf("Reconnection was successfull!\n")
		mysqldb = db
	}
	return
}

// Logas - is the logging function, that pass text to the log object and later to the log file
func Logas(text string, a ...interface{}) {
	if settings.Logging == true {
		Log.Printf(text, a...)
	}
}

// AddToQueue - Adds a task to the queue for classify and queue what is what for
func AddToQueue(item Message) {
	heap.Push(&priorityQueue, &QueueItem{Type: item.Type, Priority: item.Priority, Queue: item})
	if DEBUG > 0 {
		log.Printf("Queue added: %v\n", item)
	}
}

// Message - is the received message declaration
type Message struct {
	Type     int    `json:type`
	Customer int    `json:customer`
	Priority int    `json:priority`
	Val      string `json:val`
}

type MailList_Row struct {
	Id    int        `db:"id"`
	Uid   string     `db:"uid"`
	Name  string     `db:"name"`
	Cache NullString `db:"cache"`
}

type MailList_Cache struct {
	SubscriberCount               int
	VerifiedSubscriberCount       int
	ClickedRate                   float64
	UniqOpenRate                  float64
	OpenUniqCount                 int
	SubscribeCount                int
	ClickCount                    int
	ClickUniqCount                int
	UnsubscribeCount              int
	UnconfirmedCount              int
	BlacklistedCount              int
	SpamReportedCount             int
	SegmentSelectOptions          []string
	LongName                      string
	VerifiedSubscribersPercentage int
}

type CountSQL struct {
	Count int `db:"count"`
}

// CUSTOM NULL Handling structures

// NullInt64 is an alias for sql.NullInt64 data type
type NullInt64 sql.NullInt64

// Scan implements the Scanner interface for NullInt64
func (ni *NullInt64) Scan(value interface{}) error {
	var i sql.NullInt64
	if err := i.Scan(value); err != nil {
		return err
	}

	// if nil then make Valid false
	if reflect.TypeOf(value) == nil {
		*ni = NullInt64{i.Int64, false}
	} else {
		*ni = NullInt64{i.Int64, true}
	}
	return nil
}

// NullBool is an alias for sql.NullBool data type
type NullBool sql.NullBool

// Scan implements the Scanner interface for NullBool
func (nb *NullBool) Scan(value interface{}) error {
	var b sql.NullBool
	if err := b.Scan(value); err != nil {
		return err
	}

	// if nil then make Valid false
	if reflect.TypeOf(value) == nil {
		*nb = NullBool{b.Bool, false}
	} else {
		*nb = NullBool{b.Bool, true}
	}

	return nil
}

// NullFloat64 is an alias for sql.NullFloat64 data type
type NullFloat64 sql.NullFloat64

// Scan implements the Scanner interface for NullFloat64
func (nf *NullFloat64) Scan(value interface{}) error {
	var f sql.NullFloat64
	if err := f.Scan(value); err != nil {
		return err
	}

	// if nil then make Valid false
	if reflect.TypeOf(value) == nil {
		*nf = NullFloat64{f.Float64, false}
	} else {
		*nf = NullFloat64{f.Float64, true}
	}

	return nil
}

// NullString is an alias for sql.NullString data type
type NullString sql.NullString

// Scan implements the Scanner interface for NullString
func (ns *NullString) Scan(value interface{}) error {
	var s sql.NullString
	if err := s.Scan(value); err != nil {
		return err
	}

	// if nil then make Valid false
	if reflect.TypeOf(value) == nil {
		*ns = NullString{s.String, false}
	} else {
		*ns = NullString{s.String, true}
	}

	return nil
}

// NullTime is an alias for mysql.NullTime data type
type NullTime mysql.NullTime

// Scan implements the Scanner interface for NullTime
func (nt *NullTime) Scan(value interface{}) error {
	var t mysql.NullTime
	if err := t.Scan(value); err != nil {
		return err
	}

	// if nil then make Valid false
	if reflect.TypeOf(value) == nil {
		*nt = NullTime{t.Time, false}
	} else {
		*nt = NullTime{t.Time, true}
	}

	return nil
}

// MarshalJSON for NullInt64
func (ni *NullInt64) MarshalJSON() ([]byte, error) {
	if !ni.Valid {
		return []byte("null"), nil
	}
	return json.Marshal(ni.Int64)
}

// UnmarshalJSON for NullInt64
func (ni *NullInt64) UnmarshalJSON(b []byte) error {
	err := json.Unmarshal(b, &ni.Int64)
	ni.Valid = (err == nil)
	return err
}

// MarshalJSON for NullBool
func (nb *NullBool) MarshalJSON() ([]byte, error) {
	if !nb.Valid {
		return []byte("null"), nil
	}
	return json.Marshal(nb.Bool)
}

// UnmarshalJSON for NullBool
func (nb *NullBool) UnmarshalJSON(b []byte) error {
	err := json.Unmarshal(b, &nb.Bool)
	nb.Valid = (err == nil)
	return err
}

// MarshalJSON for NullFloat64
func (nf *NullFloat64) MarshalJSON() ([]byte, error) {
	if !nf.Valid {
		return []byte("null"), nil
	}
	return json.Marshal(nf.Float64)
}

// UnmarshalJSON for NullFloat64
func (nf *NullFloat64) UnmarshalJSON(b []byte) error {
	err := json.Unmarshal(b, &nf.Float64)
	nf.Valid = (err == nil)
	return err
}

// MarshalJSON for NullString
func (ns *NullString) MarshalJSON() ([]byte, error) {
	if !ns.Valid {
		return []byte("null"), nil
	}
	return json.Marshal(ns.String)
}

// UnmarshalJSON for NullString
func (ns *NullString) UnmarshalJSON(b []byte) error {
	err := json.Unmarshal(b, &ns.String)
	ns.Valid = (err == nil)
	return err
}

// MarshalJSON for NullTime
func (nt *NullTime) MarshalJSON() ([]byte, error) {
	if !nt.Valid {
		return []byte("null"), nil
	}
	val := fmt.Sprintf("\"%s\"", nt.Time.Format(time.RFC3339))
	return []byte(val), nil
}

// UnmarshalJSON for NullTime
func (nt *NullTime) UnmarshalJSON(b []byte) error {
	s := string(b)
	// s = Stripchars(s, "\"")

	x, err := time.Parse(time.RFC3339, s)
	if err != nil {
		nt.Valid = false
		return err
	}

	nt.Time = x
	nt.Valid = true
	return nil
}

// UpdateListCache - function that generates caches for maillists
func UpdateListCache(msg Message) {
	log.Printf("Received a request to update cache for MailList: %s\n", msg.Val)
	//	fmt.Printf("Simulating list update process for uid: %s\n", msg.Val)
	dbconn()

	// Build query here
	query := fmt.Sprintf("SELECT id,uid,name,cache from mail_lists WHERE customer_id = %d AND uid='%s'", msg.Customer, msg.Val)
	MailListItem := MailList_Row{}
	err := mysqldb.Get(&MailListItem, query)
	if err != nil {
		log.Printf("Error quering the database: %s\n", err)
		return
	}
	SubscriberCount, err := ListCount(MailListItem.Id)
	if err != nil {
		SubscriberCount = 0
	}

	VerifiedSubscriberCount, err := MailListSubscribersVerifiedCount(MailListItem.Id)
	if err != nil {
		VerifiedSubscriberCount = 0
	}

	ClickedRate, err := MailListSubscribersClickRate(MailListItem.Id)
	if err != nil {
		ClickedRate = 0
	}
	if math.Signbit(ClickedRate) {
		ClickedRate = 0
	}
	//	if Positive(int(ClickedRate)) == false {
	//		ClickedRate = 0
	//	}

	OpenRate, err := MailListSubscribersUniqOpenRate(MailListItem.Id)
	if err != nil {
		OpenRate = 0
	}
	if math.Signbit(OpenRate) {
		OpenRate = 0
	}
	//	if Positive(int(OpenRate)) == false {
	//		OpenRate = 0
	//	}

	OpenUniqCount, err := MailListOpenUniqCount(MailListItem.Id)
	if err != nil {
		OpenUniqCount = 0
	}

	SubscribeCount, err := MailListSubscribersSubscribedCount(MailListItem.Id)
	if err != nil {
		SubscribeCount = 0
	}

	ClickUniqCount, err := MailListClickUniqCount(MailListItem.Id)
	if err != nil {
		ClickUniqCount = 0
	}

	ClickCount, err := MailListClickCount(MailListItem.Id)
	if err != nil {
		ClickCount = 0
	}

	UnsubscribeCount, err := MailListSubscribersUnsubscribedCount(MailListItem.Id)
	if err != nil {
		UnsubscribeCount = 0
	}

	UnconfirmedCount, err := MailListSubscribersUnconfirmedCount(MailListItem.Id)
	if err != nil {
		UnconfirmedCount = 0
	}

	BlacklistedCount, err := MailListSubscribersBlacklistedCount(MailListItem.Id)
	if err != nil {
		BlacklistedCount = 0
	}

	SpamReportedCount, err := MailListSubscribersSpamReportedCount(MailListItem.Id)
	if err != nil {
		SpamReportedCount = 0
	}

	log.Printf("Mail list: %s got %d rows\n", MailListItem.Name, SubscriberCount)
	Cache := MailList_Cache{}
	// if cache is not NULL, if null we will fill it up :)
	if MailListItem.Cache.Valid {
		err = json.Unmarshal([]byte(MailListItem.Cache.String), &Cache)
		if err != nil {
			log.Printf("Cache is empty maybe the list is new :)\n")
		}
	}

	// update list cache here
	if DEBUG > 0 {
		log.Printf("Before modifications cache is: %s\n", MailListItem.Cache)
	}
	// Cache layout
	Cache.SubscriberCount = SubscriberCount
	Cache.VerifiedSubscriberCount = VerifiedSubscriberCount
	Cache.ClickedRate = Truncate(ClickedRate)
	Cache.UniqOpenRate = Truncate(OpenRate)
	Cache.OpenUniqCount = OpenUniqCount
	Cache.SubscribeCount = SubscribeCount
	Cache.ClickUniqCount = ClickUniqCount
	Cache.ClickCount = ClickCount

	Cache.UnsubscribeCount = UnsubscribeCount
	Cache.UnconfirmedCount = UnconfirmedCount
	Cache.BlacklistedCount = BlacklistedCount
	Cache.SpamReportedCount = SpamReportedCount
	Cache.LongName = fmt.Sprintf("%s (%d Subscribers)", MailListItem.Name, SubscriberCount)
	Cache.VerifiedSubscribersPercentage = int(math.Round(float64(VerifiedSubscriberCount) / float64(SubscriberCount) * 100))
	if DEBUG > 0 {
		log.Printf("After modification cache is: %v\n", Cache)
	}
	CacheJson, err := json.Marshal(&Cache)
	if err != nil {
		log.Printf("Problem in serializing cache data in json for mail list %s\n", msg.Val)
		return
	}
	err = redisdb.Set(fmt.Sprintf("maillist_%s_cache", MailListItem.Uid), CacheJson, 0).Err()
	if err != nil {
		log.Printf("Unable to set cache for MailList: %s to the redis backend!\n", MailListItem.Uid)
	}
	//time.Sleep(5 * time.Second)
	query = fmt.Sprintf("UPDATE mail_lists set cache = '%s' WHERE id = %d", CacheJson, MailListItem.Id)
	_, err = mysqldb.Exec(query)
	if err != nil {
		log.Printf("Error accoured while updating the mail list %s: %s\n", msg.Val, err)
		return
	} else {
		log.Printf("List cache update for uid: %s is complete!\n", msg.Val)
	}
	UpdateCustomerCache(msg.Customer)

}

func Truncate(some float64) float64 {
	return float64(int(some*100)) / 100
}

type Customer_CacheItem struct {
	Id    int    `json:"id"`
	Value string `json:"value"`
	Text  string `json:"text"`
}

type Customer_Cache struct {
	SubscriberCount       int
	SubscriberUsage       int
	SubscribedCount       int
	UnsubscribedCount     int
	UnconfirmedCount      int
	BlacklistedCount      int
	SpamReportedCount     int
	MailListSelectOptions []Customer_CacheItem
	OpenerCount           int
}

// UpdateCustomerCache - Generates the cache for customer
func UpdateCustomerCache(customer_id int) {
	dbconn()
	total_subscriberCount := 0

	cst := Customer_Cache{}
	// We could also implement blacklists and other counts here as needed

	// Here are the cache grabbing from all the lists
	Rows := []MailList_Row{}
	mysqldb.Select(&Rows, fmt.Sprintf("SELECT id,uid,name,cache from mail_lists WHERE customer_id = %d and del_date is NULL", customer_id))
	for _, row := range Rows {
		//		fmt.Printf("Got list: %s\n", row.Name)
		ListCache := MailList_Cache{}
		if row.Cache.Valid {
			json.Unmarshal([]byte(row.Cache.String), &ListCache)
			list := Customer_CacheItem{}
			list.Id = row.Id
			list.Value = row.Uid
			list.Text = ListCache.LongName
			total_subscriberCount = total_subscriberCount + ListCache.SubscriberCount
			cst.MailListSelectOptions = append(cst.MailListSelectOptions, list)
		}
	}
	cst.SubscribedCount = total_subscriberCount
	cst.SubscriberCount = total_subscriberCount
	// serialization
	json, err := json.Marshal(&cst)
	if err != nil {
		log.Print("Cannot serialize cahe information for customer: %d\n", customer_id)
		return
	}
	err = redisdb.Set(fmt.Sprintf("customer_%d_cache", customer_id), json, 0).Err()
	if err != nil {
		log.Printf("Unable to set cache for customer: %d to the redis backend!\n", customer_id)
	}
	// print debug json info
	if DEBUG > 0 {
		log.Printf("Marshallowed customer: %d json data: %s\n", customer_id, json)
		log.Printf("Saving customer: %d data\n", customer_id)
	}
	query := fmt.Sprintf("UPDATE customers set cache = '%s' WHERE id = %d", json, customer_id)
	_, err = mysqldb.Exec(query)
	if err != nil {
		log.Printf("Error accoured while updating the customer %d cache\n", customer_id)
	} else {
		log.Printf("Cache update for customer: %d is complete!\n", customer_id)
	}
}

// MailListSubscribersVerifiedCount - Collects subscribers count without hardbounces (VerifiedSubscriberCount)
func MailListSubscribersVerifiedCount(id int) (int, error) {
	dbconn()
	query := fmt.Sprintf("select count(id) as count from subscribers where subscribers.mail_list_id = %d and subscribers.email NOT IN (select email from blacklists)", id)
	Countas := CountSQL{}
	err := mysqldb.Get(&Countas, query)
	if err != nil {
		log.Printf("Error accoured while counting members for mail list %d", id)
		return 0, err
	}
	return Countas.Count, err
}

// MailListSubscribersClickRate - return round(($this->clickCount() / $this->trackingCount()) * 100, 2); (ClickedRate)
func MailListSubscribersClickRate(id int) (float64, error) {
	trackingCount, err := MailListTrackingCount(id)
	if err != nil {
		log.Printf("Got problems getting the tracking logs for maillist %d", id)
		return 0, err
	}
	ClickCount, err := MailListClickUniqCount(id)
	if err != nil {
		log.Printf("Got problems getting the click logs for maillist %d", id)
		return 0, err
	}

	ClickRate := float64(ClickCount) / float64(trackingCount) * 100
	return ClickRate, nil
}

// MailListSubscribersUniqOpenRate - return round(($this->openUniqCount() / $this->trackingCount()) * 100, 2); (UniqOpenRate)
func MailListSubscribersUniqOpenRate(id int) (float64, error) {
	trackingCount, err := MailListTrackingCount(id)
	if err != nil {
		log.Printf("Got problems getting the tracking logs for maillist %d", id)
		return 0, err
	}
	OpenCount, err := MailListOpenUniqCount(id)
	if err != nil {
		log.Printf("Got problems getting the open logs for maillist %d", id)
		return 0, err
	}

	OpenRate := float64(OpenCount) / float64(trackingCount) * 100
	//        fmt.Printf("MailListSubscribersUniqOpenRate: track count: %f opens: %f rate: %f\n",float64(trackingCount),float64(OpenCount),OpenRate)
	return OpenRate, nil
}

// MailListTrackingCount - Collects how much tracking logs maillist has (trackingCount)
func MailListTrackingCount(id int) (int, error) {
	dbconn()
	// FIXME not known if it will count the unique clicks or just plain clicks, whatever, needs to test count(distinct(subscribers.id)) or count(distinct(open_logs.id))
	query := fmt.Sprintf("select count(distinct(tracking_logs.message_id)) as count from tracking_logs inner join subscribers on tracking_logs.subscriber_id = subscribers.id where subscribers.mail_list_id = %d", id)
	Countas := CountSQL{}
	err := mysqldb.Get(&Countas, query)
	if err != nil {
		log.Printf("Error accoured while counting members for mail list %d", id)
		return 0, err
	}
	return Countas.Count, err
}

// MailListOpenUniqCount - Collects subscribers which are made unique opens (OpenUniqCount)
func MailListOpenUniqCount(id int) (int, error) {
	dbconn()
	// FIXME not known if it will count the unique clicks or just plain clicks, whatever, needs to test count(distinct(subscribers.id)) or count(distinct(open_logs.id))
	query := fmt.Sprintf("select count(distinct(subscribers.email)) as count from open_logs left join tracking_logs ON open_logs.message_id = tracking_logs.message_id inner join subscribers on tracking_logs.subscriber_id = subscribers.id where subscribers.mail_list_id = %d", id)
	Countas := CountSQL{}
	err := mysqldb.Get(&Countas, query)
	if err != nil {
		log.Printf("Error accoured while counting members for mail list %d", id)
		return 0, err
	}
	return Countas.Count, err
}

// MailListSubscribersSubscribedCount - Collects subscribers that are subscribed without blacklist checking etc.. (SubscribeCount)
func MailListSubscribersSubscribedCount(id int) (int, error) {
	dbconn()
	query := fmt.Sprintf("select count(subscribers.id) as count from subscribers where subscribers.mail_list_id = %d and subscribers.status = 'subscribed'", id)
	Countas := CountSQL{}
	err := mysqldb.Get(&Countas, query)
	if err != nil {
		log.Printf("Error accoured while counting members for mail list %d", id)
		return 0, err
	}
	return Countas.Count, err
}

// MailListClickUniqCount - Collects subscribers which are made unique clicks (ClickUniqCount)
func MailListClickUniqCount(id int) (int, error) {
	dbconn()
	// FIXME not known if it will count the unique clicks or just plain clicks, whatever, needs to test count(distinct(subscribers.id)) or count(distinct(click_logs.id))
	query := fmt.Sprintf("select count(distinct(subscribers.email)) as count from click_logs left join tracking_logs on click_logs.message_id = tracking_logs.message_id inner join subscribers on tracking_logs.subscriber_id = subscribers.id where subscribers.mail_list_id = %d", id)
	Countas := CountSQL{}
	err := mysqldb.Get(&Countas, query)
	if err != nil {
		log.Printf("Error accoured while counting uniq members for mail list %d", id)
		return 0, err
	}
	return Countas.Count, err
}

// MailListClickCount - Collects total clicks (clickCount())
func MailListClickCount(id int) (int, error) {
	dbconn()
	query := fmt.Sprintf("select count(distinct(subscribers.email)) as count from click_logs left join tracking_logs ON click_logs.message_id = tracking_logs.message_id inner join subscribers on tracking_logs.subscriber_id = subscribers.id where subscribers.mail_list_id = %d", id)
	Countas := CountSQL{}
	err := mysqldb.Get(&Countas, query)
	if err != nil {
		log.Printf("Error accoured while counting members for list %d", id)
		return 0, err
	}
	return Countas.Count, err
}

// MailListSubscribersUnsubscribedCount - Collects subscribers much are unsubscribed from this maillist (UnsubscribeCount)
func MailListSubscribersUnsubscribedCount(id int) (int, error) {
	dbconn()
	query := fmt.Sprintf("select count(subscribers.id) as count from subscribers inner join tracking_logs on subscribers.id = tracking_logs.subscriber_id inner join unsubscribe_logs on tracking_logs.message_id = unsubscribe_logs.message_id where subscribers.mail_list_id = %d", id)
	Countas := CountSQL{}
	err := mysqldb.Get(&Countas, query)
	if err != nil {
		log.Printf("Error accoured while counting members for mail list %d", id)
		return 0, err
	}
	return Countas.Count, err
}

// MailListSubscribersUnconfirmedCount - FIXME for now only returns 0, i dunno what this come from
func MailListSubscribersUnconfirmedCount(id int) (int, error) {
	return 0, nil
}

// MailListSubscribersBlacklistedCount - Collects subscribers how much where blacklisted on maillist (BlacklistedCount)
func MailListSubscribersBlacklistedCount(id int) (int, error) {
	dbconn()
	query := fmt.Sprintf("select count(subscribers.id) as count from subscribers inner join blacklists on subscribers.email = blacklists.email where subscribers.mail_list_id = %d", id)
	Countas := CountSQL{}
	err := mysqldb.Get(&Countas, query)
	if err != nil {
		log.Printf("Error accoured while counting members for mail list %d", id)
		return 0, err
	}
	return Countas.Count, err
}

// MailListSubscribersSpamReportedCount - Collects subscribers count that where in abuse blacklist (SpamReportedCount)
func MailListSubscribersSpamReportedCount(id int) (int, error) {
	dbconn()
	query := fmt.Sprintf("select count(subscribers.id) as count from subscribers inner join blacklist_abuse on subscribers.email = blacklist_abuse.email where subscribers.mail_list_id = %d", id)
	Countas := CountSQL{}
	err := mysqldb.Get(&Countas, query)
	if err != nil {
		log.Printf("Error accoured while counting members for mail list %d", id)
		return 0, err
	}
	return Countas.Count, err
}

func ListCount(id int) (int, error) {
	dbconn()
	query := fmt.Sprintf("SELECT count(id) as count from subscribers where mail_list_id = %d", id)
	Countas := CountSQL{}
	err := mysqldb.Get(&Countas, query)
	if err != nil {
		log.Printf("Error accoured while counting members for mail list %d", id)
		return 0, err
	}
	return Countas.Count, err
}

// Positive returns true if the number is positive, false if it is negative.
func Positive(n int) bool {
	if n == 0 {
		return false
		//panic("undefined")
	}
	return n > -1
}

type Campaign_Row struct {
	Id    int        `db:"id"`
	Uid   string     `db:"uid"`
	Name  string     `db:"name"`
	Cache NullString `db:"cache"`
}

type Campaign_Cache struct {
	SubscriberCount int
	DeliveredRate   int
	DeliveredCount  int
	ClickedRate     int
	UniqOpenRate    int
	UniqOpenCount   int
	NotOpenRate     int
	NotOpenCount    int
}

func UpdateCampaignCache(msg Message) {
	dbconn()
	log.Printf("Received a request to update cache for campaign: %s\n", msg.Val)
	query := fmt.Sprintf("SELECT id,uid,name,cache from campaigns WHERE customer_id = %d AND uid='%s'", msg.Customer, msg.Val)
	CampaignItem := Campaign_Row{}
	err := mysqldb.Get(&CampaignItem, query)
	if err != nil {
		log.Printf("Error when quering for campaign: %s database error: %s\n", msg.Val, err)
		return
	}
	SubscriberCount, err := CampaignTotalSubscribers(CampaignItem)
	if err != nil {
		SubscriberCount = 0
	}
	Delivered := CampaignAlreadyDelivered(CampaignItem.Uid)
	DeliveredRate := 0
	if Delivered > 0 {
		DeliveredRate = int(Delivered / SubscriberCount)
	}
	if Positive(DeliveredRate) == false {
		DeliveredRate = 0
	}
	UniqClicks := CampaignClickedEmailsCount(CampaignItem)
	ClickedRate := 0
	if UniqClicks > 0 && Delivered > 0 {
		ClickedRate = int((UniqClicks / Delivered) * 100)
	}
	if Positive(ClickedRate) == false {
		ClickedRate = 0
	}

	UniqOpenCount := CampaignOpenedEmailsCount(CampaignItem)
	UniqOpenRate := 0
	if UniqOpenCount > 0 && Delivered > 0 {
		UniqOpenRate = int((UniqOpenCount / Delivered) * 100)
	}
	if Positive(UniqOpenRate) == false {
		UniqOpenRate = 0
	}
	NotOpenCount := 0
	if SubscriberCount > 0 {
		NotOpenCount = int(SubscriberCount - UniqOpenCount)
	}
	if Positive(NotOpenCount) == false {
		NotOpenCount = 0
	}
	NotOpenRate := 0
	if NotOpenCount > 0 && SubscriberCount > 0 {
		NotOpenRate = int((NotOpenCount / SubscriberCount) * 100)
	}
	if Positive(NotOpenRate) == false {
		NotOpenRate = 0
	}

	Cache := Campaign_Cache{}
	// if cache is not NULL, if null we will fill it up :)
	if CampaignItem.Cache.Valid {
		err = json.Unmarshal([]byte(CampaignItem.Cache.String), &Cache)
		if err != nil {
			fmt.Printf("Cache is empty maybe the campaign is new :)\n")
		}
	}

	// update list cache here
	if DEBUG > 0 {
		log.Printf("Before modifications campaign cache is: %s\n", CampaignItem.Cache)
	}
	// Cache layout
	Cache.SubscriberCount = SubscriberCount
	Cache.DeliveredRate = DeliveredRate
	Cache.DeliveredCount = Delivered
	Cache.ClickedRate = ClickedRate
	Cache.UniqOpenRate = UniqOpenRate
	Cache.UniqOpenCount = UniqOpenCount
	Cache.NotOpenRate = NotOpenRate
	Cache.NotOpenCount = NotOpenCount
	if DEBUG > 0 {
		log.Printf("After modification cache is: %v\n", Cache)
	}
	CacheJson, err := json.Marshal(&Cache)
	if err != nil {
		log.Printf("Problem in serializing cache data in json for campaign %s\n", msg.Val)
		return
	}
	err = redisdb.Set(fmt.Sprintf("campaign_%s_cache", CampaignItem.Uid), CacheJson, 0).Err()
	if err != nil {
		log.Printf("Unable to set cache for campaign: %s to the redis backend!\n", CampaignItem.Uid)
	}
	query = fmt.Sprintf("UPDATE campaigns set cache = '%s' WHERE id = %d", CacheJson, CampaignItem.Id)
	_, err = mysqldb.Exec(query)
	if err != nil {
		log.Printf("Error accoured while updating the campaign: %s cache: %s\n", msg.Val, err)
		return
	} else {
		log.Printf("Campaign cache update for uid: %s is complete!\n", msg.Val)
	}

	// here we finish
}

// CampaignOpenedEmailsCount - Unique opens for campaign
func CampaignOpenedEmailsCount(camp Campaign_Row) int {
	dbconn()
	query := fmt.Sprintf("select count(distinct(subscriber_id)) as count from tracking_logs inner join open_logs on tracking_logs.message_id = open_logs.message_id where tracking_logs.campaign_id = %d", camp.Id)
	Countas := CountSQL{}
	err := mysqldb.Get(&Countas, query)
	if err != nil {
		log.Printf("Error accoured while counting unique opens for campaign %d\n", camp.Id)
		return 0
	}
	return Countas.Count
}

// CampaignClickedEmailsCount - Unique clicks for campaign
func CampaignClickedEmailsCount(camp Campaign_Row) int {
	dbconn()
	query := fmt.Sprintf("select count(distinct(subscriber_id)) as count from tracking_logs inner join click_logs on tracking_logs.message_id = click_logs.message_id where tracking_logs.campaign_id = %d", camp.Id)
	Countas := CountSQL{}
	err := mysqldb.Get(&Countas, query)
	if err != nil {
		log.Printf("Error accoured while counting unique clicks for campaign %d\n", camp.Id)
		return 0
	}
	return Countas.Count
}

// CampaignAlreadyDelivered - Shows the sent count
func CampaignAlreadyDelivered(uid string) int {
	exists, err := redisdb.Exists(uid + "_counter").Result()
	if err == nil {
		if exists == 1 {
			delivered, err := redisdb.Get(uid + "_counter").Int()
			if err == nil {
				return delivered
			}
			if DEBUG > 0 {
				log.Printf("Unable to determine the delivered count for campaign: %s", uid)
			}
		}
	}
	return 0
}

func CampaignTotalSubscribers(camp Campaign_Row) (int, error) {
	exists, err := redisdb.Exists(camp.Uid + "_total").Result()
	var total int
	if err == nil {
		if exists == 1 {
			tmptot, err := redisdb.Get(camp.Uid + "_total").Int()
			if err == nil {
				total = tmptot
			}
		}
	}
	// manual query from mysql
	if total == 0 {
		//total = CampaignTrackingLogsCount(camp.Id)
		dbconn()
		query := fmt.Sprintf("select count(distinct(subscribers.id)) as count from subscribers inner join mail_lists on subscribers.mail_list_id = mail_lists.id inner join campaigns_lists_segments on mail_lists.id = campaigns_lists_segments.mail_list_id WHERE campaigns_lists_segments.campaign_id = %d", camp.Id)
		Countas := CountSQL{}
		err := mysqldb.Get(&Countas, query)
		if err != nil {
			log.Printf("Error accoured while counting subscriber total for campaign %d\n", camp.Id)
			Countas.Count = 0
		}
		total = Countas.Count
	}
	return total, err
}

func CampaignTrackingLogsCount(id int) int {
	dbconn()
	query := fmt.Sprintf("select count(id) as count from tracking_logs where campaign_id = %d", id)
	Countas := CountSQL{}
	err := mysqldb.Get(&Countas, query)
	if err != nil {
		log.Printf("Error accoured while counting tracking_logs for campaign %d\n", id)
		return 0
	}
	return Countas.Count
}
