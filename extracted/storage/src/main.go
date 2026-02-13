/*
This is the latest version 1.2
build 2019.12.09
Storage (c) 2019 justinas@eofnet.lt
go get github.com/go-sql-driver/mysql
go get github.com/jmoiron/sqlx
go get github.com/gomodule/redigo/redis
go get github.com/go-redis/redis
go get github.com/nitishm/go-rejson
go get github.com/valyala/fasthttp
go get github.com/qiangxue/fasthttp-routing

*/

package main

import (
	"bufio"
	"database/sql"
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"net"
	"os"
	"regexp"
	"strings"
	"sync"
	"time"
	"io"
//	"io/ioutil"
	"github.com/go-redis/redis"
	_ "github.com/go-sql-driver/mysql"
	"github.com/jmoiron/sqlx"
//	"github.com/paulbellamy/ratecounter"
)

var (
	Version                  string
	Build                    string
	Commit                   string
	load_file                = "storage_emails_wmxentries.txt"
	file_with_extracted_data = "storage_emails_wmxentries.txt"
	thread_count             int
	thread_limit             = 10
	mysqldb                  *sqlx.DB
	redisdb                  *redis.Client
	BENCHMARK                = 0
	settings                 Settings
	ProgramPath              string
	Log                      *log.Logger
	Mxes                     = make(map[string]string, 0)
	// THIS REGEXP IS TOTALLY DAMN WRONG SHIT!!!
	emailRegexp = regexp.MustCompile("^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$")
	// rfc5322 is a RFC 5322 regex, as per: https://stackoverflow.com/a/201378/5405453.
	// Note that this can't verify that the address is an actual working email address.
	// Use ValidateHost as a starter and/or send them one :-).
	rfc5322            = "(?i)(?:[a-z0-9!#$%&'*+/=?^_`{|}~-]+(?:\\.[a-z0-9!#$%&'*+/=?^_`{|}~-]+)*|\"(?:[\\x01-\\x08\\x0b\\x0c\\x0e-\\x1f\\x21\\x23-\\x5b\\x5d-\\x7f]|\\\\[\\x01-\\x09\\x0b\\x0c\\x0e-\\x7f])*\")@(?:(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?|\\[(?:(?:(2(5[0-5]|[0-4][0-9])|1[0-9][0-9]|[1-9]?[0-9]))\\.){3}(?:(2(5[0-5]|[0-4][0-9])|1[0-9][0-9]|[1-9]?[0-9])|[a-z0-9-]*[a-z0-9]:(?:[\\x01-\\x08\\x0b\\x0c\\x0e-\\x1f\\x21-\\x5a\\x53-\\x7f]|\\\\[\\x01-\\x09\\x0b\\x0c\\x0e-\\x7f])+)\\])"
	validRfc5322Regexp = regexp.MustCompile(fmt.Sprintf("^%s*$", rfc5322))
	findRfc5322Regexp  = regexp.MustCompile(rfc5322)

	// findCommonRegexp is a stricter regex than the RFC 5322 and matches emails that
	// are more likely to be real.
	findCommonRegexp = regexp.MustCompile("(?i)([A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,24})")
)

func TrimChars(text string) string {
	s := strings.TrimSpace(text)
	text = strings.TrimSuffix(s, "\n")
	return text
}

func dbconn() {
	if mysqldb == nil {
		db, err := sqlx.Connect("mysql", fmt.Sprintf("%s:%s@tcp(%s:%d)/%s", settings.SqlUser, settings.SqlPass, settings.SqlHost, settings.SqlPort, settings.SqlDba))
		fmt.Printf("%s:%s@tcp(%s:%d)/%s\n", settings.SqlUser, settings.SqlPass, settings.SqlHost, settings.SqlPort, settings.SqlDba)
		if err != nil {
			fmt.Printf("Got error then tried to contact to mysql server %s\n", err)
			return
		}
		mysqldb = db
		return
	}

	err := mysqldb.Ping()
	if err != nil {
		mysqldb = nil
		fmt.Printf("Unable to ping the MySQL connection, it's lost!! Reconnecting...\n")
		db, err := sqlx.Connect("mysql", fmt.Sprintf("%s:%s@tcp(%s:%d)/%s", settings.SqlUser, settings.SqlPass, settings.SqlHost, settings.SqlPort, settings.SqlDba))
		if err != nil {
			fmt.Printf("Got error then tried to contact to mysql server %s\n", err)
			return
		}
		fmt.Printf("Reconnection was successfull!\n")
		mysqldb = db
	}
	return
}

type Item struct {
	Email string         `db:"email"`
	Mx    sql.NullString `db:"mx"`
}

func ToSQL(text string, thread int) {
	if thread > 0 {
		fmt.Printf("Additional thread started... TH(%v of %v)\n", thread_count, thread_limit)
		thread_count = thread_count + 1
	}
	s := strings.Split(TrimChars(text), "::")
	email, mx := s[0], s[1]
	fmt.Printf("Inserting %s (%s) to sql...\n", email, mx)
	dbconn()
	v := Item{Email: email, Mx: sql.NullString{String: mx, Valid: true}}
	stmt, err := mysqldb.PrepareNamed(fmt.Sprintf("INSERT INTO %s (email,mx) VALUES (:email, :mx)", settings.SqlStorageTable))
	if err != nil {
		fmt.Printf("Got error in formulating the sql insert: %s\n", err)
		return
	}
	_, err = stmt.Exec(v)
	defer stmt.Close()
	if err != nil {
		fmt.Printf("Got error: %s\n", err)
		return
	}
	if thread > 0 {
		thread_count = thread_count - 1
	}
}

type StorageRow struct {
	Id        int            `db:"id"`
	Email     sql.NullString `db:"email"`
	Domain    sql.NullString `db:"domain"`
	Mx        sql.NullString `db:"mx"`
	Ip        sql.NullString `db:"ip"`
	UserAgent sql.NullString `db:"user_agent"`
	Location  sql.NullString `db:"location"`
}

type RedisItem struct {
	Id        int    `json:"id"`
	Email     string `json:"email"`
	Domain    string `json:"domain"`
	Mx        string `json:"mx"`
	Ip        string `json:"ip"`
	UserAgent string `json:"user_agent"`
	Location  string `json:"location"`
}

func LoadToRedis() {
	dbconn()
	Rows := []StorageRow{}
	fmt.Printf("Loading SQL data to memory...\n")
	mysqldb.Select(&Rows, fmt.Sprintf("SELECT id,email,domain,mx,ip,user_agent,location from %s", settings.SqlStorageTable))
	fmt.Printf("Populating SQL data to REDIS backend...\n")
	RedisInit()
	count := 1
	for _, row := range Rows {
		//fmt.Printf("Got email: %s\n", row.Email.String)
		Store := RedisItem{Id: row.Id, Email: row.Email.String, Domain: row.Domain.String, Mx: row.Mx.String, Ip: row.Ip.String, UserAgent: row.UserAgent.String, Location: row.Location.String}
		Jsonas, err := json.Marshal(&Store)
		err = redisdb.HSet(ReturnRDTable(settings.RedisTable), row.Email.String, Jsonas).Err()
		fmt.Printf("Populating data entries %v already done...\r", count)
		if err != nil {
			fmt.Printf("Problem in storing email: %s to redis backend!", row.Email.String)
		}
		count++
	}
	fmt.Printf("We have done populating all the data to redis!\n")
}

func Daemonize() {
	fmt.Printf("Daemonizing...\n")
	RedisInit()
	go APIPool()
	go QueueWatcher()
	go LinksPool()
	go TrackingQueuePool()
}

func DNSResolver(domain string) string {
	var mxs []*net.MX
	mxs, err := net.LookupMX(domain)
	if err != nil {
		return ""
	}
	mx_addresses := ""
	count := 0
	for _, x := range mxs {
		count++
		// removing ending point here
		if strings.HasSuffix(x.Host, ".") {
			x.Host = x.Host[:len(x.Host)-len(".")]
		}
		if count > 1 {
			mx_addresses = mx_addresses + "," + x.Host
		} else {
			mx_addresses = x.Host
		}
	}
	return mx_addresses

	return ""
}

func EmailValidator(email string) bool {
	//t0 := time.Now()
	//regex := regexp.MustCompile("^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$")
	//if emailRegexp.MatchString(email) == true {
		if strings.Contains(email,"@") {
		// t2 := time.Now()
		// if BENCHMARK > 0 {
		// 	fmt.Printf("EmailValidator positive took: %s\n", t2.Sub(t0))
		// }

		return true
	}
	// t2 := time.Now()
	// if BENCHMARK > 0 {
	// 	fmt.Printf("EmailValidator negative took %s\n", t2.Sub(t0))
	// }

	return false
}

func ProcessLines(email string, results chan string, ch chan struct{}, wg *sync.WaitGroup) {
	if EmailValidator(email) == true {
		dns := DNSResolver(strings.Split(email, "@")[1])
		if dns != "" && dns != "localhost" {
			//    fmt.Printf("Email: %s with dns: %s\n",email,dns)
			results <- fmt.Sprintf("%s::%s", email, dns)
		}
	}
	<-ch
	wg.Done()
}

func ResultsWriter(fileName string, results chan string) {
	resultFile, err := os.OpenFile(fileName, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
	if err != nil {
		log.Fatal(err)
	}
	defer resultFile.Close()
	if err != nil {
		log.Fatal(err)
	}
	for r := range results {
		_, err := resultFile.WriteString(r + "\n")
		if err != nil {
			log.Fatal(err)
		}
	}
}

func ExtractDump(fileName string, continue_extract int) {
	c := make(chan struct{}, 100)
	results := make(chan string)
	file, err := os.Open(fileName)
	if err != nil {
		log.Fatal(err)
		os.Exit(-1)
	}
	defer file.Close()
	scanner := bufio.NewScanner(file)
	count := 0
	wg := sync.WaitGroup{}
	go ResultsWriter(file_with_extracted_data, results)
	for scanner.Scan() {
		count++
		if continue_extract > 0 {
			if count <= continue_extract {
				continue
			}
		}
		c <- struct{}{}
		wg.Add(1)
		go ProcessLines(scanner.Text(), results, c, &wg)
		fmt.Printf("Scanning line: %v...\r", count)
	}
	wg.Wait()
	close(c)
	close(results)
	fmt.Printf("\nAll done. extracting file: %s\n", fileName)
}

func FileCacheCounters(fileas string, force bool) {
	file, err := os.Open(fileas)
	if err != nil {
		log.Fatal(err)
		os.Exit(-1)
	}
	defer file.Close()
	scanner := bufio.NewScanner(file)
	count := 0
	Providers := make(map[string]int64, 0)
	for scanner.Scan() {
		count++
		s := strings.Split(TrimChars(scanner.Text()), "::")
		email := s[0]
		provider := ReturnDomainFromEmail(email)
		if provider != "" && len(provider) > 1 {
			clean_val := strings.TrimRight(provider, "\r\n")
			Providers[clean_val] = Providers[clean_val] + 1
		}
		fmt.Printf("Processing count: %d providers: %d\r", count, len(Providers))
		// if count > 1000 {
		// 	break
		// }
	}
	// here we will set the redis storage backend
	for key, value := range Providers {
		table := ReturnRDTable(CACHELIST_STORAGE_COUNT)
		if force == true {
			_, erro := redisdb.HDel(table, key).Result()
			if erro != nil {
				fmt.Printf("\nUnable to delete hkey %s->%s from redis backend maybe it was non existen before?\n", table, key)
			}
		}
		_, err := redisdb.HIncrBy(table, key, value).Result()
		if err != nil {
			fmt.Printf("\nUnable to hset the provider to the redis backend storage: %v\n", err)
		}
	}
	fmt.Printf("\nProcessed: %d lines gathered: %d providers\n", count, len(Providers))
}

func ExtractProviders(fileas string) {
// needs to lower string for every provider

// create directory if it's non existen
if _, err := os.Stat("./providers"); os.IsNotExist(err) {
    os.Mkdir("./providers", os.ModePerm)
}
// open providers file
file, err := os.Open("./providers.txt")
	if err != nil {
		log.Fatal(err)
		os.Exit(-1)
	}
	defer file.Close()
	scanner := bufio.NewScanner(file)
	Providers := make(map[string]int64, 0)
	WriteToFiles := make(map[string]*os.File,0)
    needs_sql := 0
	for scanner.Scan() {
		provider := strings.TrimRight(scanner.Text(),"\r\n")
		if _, err := os.Stat("./providers/"+provider); os.IsNotExist(err) {
			// provider file does not exist
			Providers[provider] = 1
			WriteToFiles[provider], _ = os.OpenFile("./providers/"+provider, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0644)
			needs_sql++
		} else {
			Providers[provider] = 0
			fmt.Printf("It seems that the provider %s already exists in ./providers directory and it's extracted :)\n",provider)
		}

	}
	fmt.Printf("We currently read the %d providers from the providers.txt\n",len(Providers))
	
	// OLD IMPLEMENTATION DOES NOT WORK AT ALL (but maybe...)
	count := 0
	got_emails := 0
	dump, err := os.Open(fileas)
 		if err != nil {
 			log.Fatal(err)
 			os.Exit(-1)
		 }
		 //dump.Seek(0,io.SeekStart)
		 defer dump.Close()
	//r := bufio.NewReaderSize(dump, 4*1024)
	r := bufio.NewReader(dump)
    for {
		count++
		line, _, err := r.ReadLine()
		if err == io.EOF {
				fmt.Printf("It seems that we have reached the file end of: %s\n",fileas)
				break
		}
        if err == nil {
		email := strings.ToLower(strings.TrimRight(string(line),"\r\n"))
		if strings.Contains(email,"@") {
			lower_provider := ReturnDomainFromEmail(email)
			if lower_provider != "" && len(lower_provider) > 0 && Providers[lower_provider] > 0 {
			got_emails++
			WriteToFiles[lower_provider].WriteString(email+"\n")
			}
		}
		
	}
	fmt.Printf("Processing dump line no.: %d total got needed emails: %d\r",count,got_emails)
    }


// 	for prov,cnt := range Providers {
// 		// if exists already, do not process it
// 		if cnt == 0 {
// 			break
// 		}
// 		dump, err := os.Open(fileas)
// 		if err != nil {
// 			log.Fatal(err)
// 			os.Exit(-1)
// 		}
// 		//dump.Seek(0,io.SeekStart)
// 		defer dump.Close()
// 		count := 0
// 		wrtn_lines := 0

// r := bufio.NewReaderSize(dump, 4*1024)
//     line, isPrefix, err := r.ReadLine()
//     for err == nil && !isPrefix {
// 		count++
//         s := strings.ToLower(string(line))
// 		//fmt.Println(s)
// 		if strings.Contains(s,"@"+prov) {
// 			wrtn_lines++
// 			WriteToFiles[prov].WriteString(s+"\n")
// 		}
// 		fmt.Printf("Processing dump line: %d for provider: %s, lines found: %d\r",count,prov,wrtn_lines)
//         line, isPrefix, err = r.ReadLine()
//     }
//     if isPrefix {
//         fmt.Println("buffer size to small")
//         return
//     }
//     if err != io.EOF {
//         fmt.Println(err)
//         return
//     }

//  	}

	for file,_ := range WriteToFiles {
		fmt.Printf("Closing filehandle to %v file\n",file)
		WriteToFiles[file].Close()
	}

	//fmt.Printf("Test phase done!\n")
	//os.Exit(0)

	if needs_sql > 0 {
		fmt.Printf("There currently are %d providers that needs to be inserted in the mysql backend\n",needs_sql)
		//dbconn()
		type TempInsertItem struct {
			Val string `db:"val"`
		}
		stmt, err := mysqldb.PrepareNamed(fmt.Sprintf("INSERT INTO %s (val) VALUES (:val)", settings.SqlStorageTable))
		for file,_ := range WriteToFiles {
		fmt.Printf("We are going to start processing provider %s file\n",file)	
		
		WriteToFiles[file],_ = os.OpenFile("./providers/"+file, os.O_RDWR, 0644)
		WriteToFiles[file].Seek(0,io.SeekStart)
		scanner3 := bufio.NewScanner(WriteToFiles[file])
		for scanner3.Scan() {
			line := strings.ToLower(strings.TrimRight(scanner3.Text(),"\r\n"))
			//fmt.Printf("Scanning file %s found line %s\n",file,line)
			_, err = stmt.Exec(TempInsertItem{Val: line})
			if err != nil {
			fmt.Printf("Error processing insert of %s error: %s\n",line,err)
			}
	}
		//fmt.Printf("Now you can easily run ./storage ... to populate data from mysql to redis backend and everything will be up :)")
	}
	defer stmt.Close()
	}

}


func SystematicLower() {
	fmt.Printf("Received a request to fix redis backend key names by lowering them (making case insensitive)...\n")
	//LowerFixRedis("testas")
	 LowerFixRedis(settings.RedisBlacklistsEmailsTable)
	 LowerFixRedis(settings.RedisBlacklistsDomainTable)
	 LowerFixRedis(settings.RedisBlacklistsNameTable)
	 LowerFixRedis(settings.RedisBlacklistsMXTable)
	 LowerFixRedis(settings.RedisDeliveryTable)
	 LowerFixRedis(settings.RedisOpenersTable)
	 LowerFixRedis(settings.RedisClickersTable)
	fmt.Printf("Data fix is completed!\n")
}

func CountDataFromFilev2(fileas string, force bool) {
	file, err := os.Open(fileas)
	if err != nil {
		log.Fatal(err)
		os.Exit(-1)
	}
	defer file.Close()
	fileout, err := os.Create("./invalid_emails.txt")
    if err != nil {
		log.Fatal(err)
        os.Exit(-1)
    }
	defer fileout.Close()
	w := bufio.NewWriter(fileout)
	//r := bufio.NewReaderSize(file, 4*1024)
	r := bufio.NewReader(file)

	count := 0
	var alive_emails int64 = 0
	invalid_emails := 0
	Providers := make(map[string]int64, 0)
    for {
		count++
		line, _, err := r.ReadLine()
		if err == io.EOF {
			fmt.Printf("It seems that we have reached the file end of: %s\n",fileas)
			break
	    }
		email := strings.ToLower(strings.TrimRight(string(line),"\r\n"))
		if strings.Contains(email,"@") {
			alive_emails++
		    provider := ReturnDomainFromEmail(email)
		    if provider != "" && len(provider) > 0 {
		     	Providers[provider] = Providers[provider] + 1
	     	}
		} else {
			// write invalid emails to file
			invalid_emails++
			fmt.Fprintln(w,email)
		}
		fmt.Printf("Processing count: %d providers: %d VALID: %d INVALID: %d\r", count, len(Providers),alive_emails,invalid_emails)
    }
   
	
	// here we will set the redis storage backend
	fmt.Printf("Starting to update redis backend with calculated counters...\n")
	for key, value := range Providers {
		table := ReturnRDTable(CACHELIST_STORAGE_COUNT)
		if force == true {
			_, erro := redisdb.HDel(table, key).Result()
			if erro != nil {
				fmt.Printf("\nUnable to delete hkey %s->%s from redis backend maybe it was non existen before?\n", table, key)
			}
		}
		_, err := redisdb.HIncrBy(table, key, value).Result()
		if err != nil {
			fmt.Printf("\nUnable to hset the provider: %s to the redis backend storage: %v\n", key,err)
		}
	}
	if force == true {
		_, errn := redisdb.Del(ReturnRDTable("absolutely_total")).Result()
		if errn != nil {
			fmt.Printf("Unable to delete key absolutely_total from redis backend, maybe it was non existen before? :)\n")
		}
	}
	_, err = redisdb.IncrBy(ReturnRDTable("absolutely_total"),alive_emails).Result()
	if err != nil {
		fmt.Printf("Unable to set absolutely_total key with count of %d\n",alive_emails)
	}
	fmt.Printf("\nProcessed: %d lines gathered: %d providers VALID EMAILS: %d INVALID: %d\n", count, len(Providers),alive_emails,invalid_emails)
	fmt.Printf("The invalid emails (total %d) have been written to file 'invalid_emails.txt'\n",invalid_emails)
}

func CountDataFromFile(fileas string, force bool) {
	file, err := os.Open(fileas)
	if err != nil {
		log.Fatal(err)
		os.Exit(-1)
	}
	defer file.Close()
	fileout, err := os.Create("./invalid_emails.txt")
    if err != nil {
		log.Fatal(err)
        os.Exit(-1)
    }
	defer fileout.Close()
	w := bufio.NewWriter(fileout)
	scanner := bufio.NewScanner(file)
	count := 0
	var alive_emails int64 = 0
	invalid_emails := 0
	Providers := make(map[string]int64, 0)
	for scanner.Scan() {
		count++
		email := strings.TrimRight(scanner.Text(),"\r\n")
		// FIXME regexp is totally wrong
		//if emailRegexp.MatchString(email) {
	    if strings.Contains(email,"@") {
		alive_emails++
		provider := ReturnDomainFromEmail(email)
		if provider != "" && len(provider) > 0 {
			Providers[provider] = Providers[provider] + 1
		}
	} else {
		// write invalid emails to the file
		invalid_emails++
		fmt.Fprintln(w, email)
	}
		fmt.Printf("Processing count: %d providers: %d VALID: %d INVALID: %d\r", count, len(Providers),alive_emails,invalid_emails)
	}
	// here we will set the redis storage backend
	fmt.Printf("Starting to update redis backend with calculated counters...\n")
	for key, value := range Providers {
		table := ReturnRDTable(CACHELIST_STORAGE_COUNT)
		if force == true {
			_, erro := redisdb.HDel(table, key).Result()
			if erro != nil {
				fmt.Printf("\nUnable to delete hkey %s->%s from redis backend maybe it was non existen before?\n", table, key)
			}
		}
		_, err := redisdb.HIncrBy(table, key, value).Result()
		if err != nil {
			fmt.Printf("\nUnable to hset the provider: %s to the redis backend storage: %v\n", key,err)
		}
	}
	if force == true {
		_, errn := redisdb.Del(ReturnRDTable("absolutely_total")).Result()
		if errn != nil {
			fmt.Printf("Unable to delete key absolutely_total from redis backend, maybe it was non existen before? :)\n")
		}
	}
	_, err = redisdb.IncrBy(ReturnRDTable("absolutely_total"),alive_emails).Result()
	if err != nil {
		fmt.Printf("Unable to set absolutely_total key with count of %d\n",alive_emails)
	}
	fmt.Printf("\nProcessed: %d lines gathered: %d providers VALID EMAILS: %d INVALID: %d\n", count, len(Providers),alive_emails,invalid_emails)
	fmt.Printf("The invalid emails (total %d) have been written to file 'invalid_emails.txt'\n",invalid_emails)
}

func main() {
	fmt.Printf("Welcome to storage for parkagency.net project (c) 2018-2020 version: %s Build: %s Commit: %s\n", Version, Build, Commit)
	load := flag.Bool("load", false, "Load SQL to REDIS")
	daemonize := flag.Bool("daemonize", false, "Run storage in httpd mode as server")
	initializeTables := flag.Bool("initialize-sql", false, "Initialize the required storage SQL tables (create new if not exist)")
	force_mode := flag.Bool("force", false, "Use force mode")
	extract := flag.Bool("extract", false, "Extract the specified --file parameter check MX entries and add all data to the database, also it can be continued from postion with --continue <pos> parameter")
	file := flag.String("file", "", "Specify file for file extract or load procedures")
	continue_extract := flag.Int("continue", 0, "Specify the file position to continue")
	cache_counters := flag.Bool("cache-counters", false, "Caches counters for all redis data tables (only redis) can be used with --force that will zeroe all counters")
	file_cache_counters := flag.Bool("file-cache-counters", false, "Caches counters from processed wmxentries file, must be provided with --file option use --force mode to clear provider data before")
	extract_providers := flag.Bool("extract-providers",false,"This parameter altogether with --file it will exctract providers specified in providers.txt and import them to the mysql")
	count_data_from_File := flag.Bool("count-data-from",false,"Altogether with --file counts all data from all text dump and updates counters, you can specify --force to reset counters to 0 instead updating them")
	lower_strings := flag.Bool("lower-sys-str",false,"Lower redis backend tables string to lower (fix on some deployments)")
	var search = ""
	flag.StringVar(&search, "search", "", "Search in the redis backend")
	flag.Parse()
	if *daemonize == true {
		LoadSettings()
		Daemonize()

		// TODO
		// here we should implement the wait channel
		for {
			time.Sleep(time.Second * 10)
			//time.Sleep(100)
		}
		os.Exit(0)
	}
	if *lower_strings == true {
		LoadSettings()
		dbconn()
		RedisInit()
		SystematicLower()
	}
	if *extract_providers == true && *file != "" {
		LoadSettings()
		dbconn()
		RedisInit()
		ExtractProviders(*file)
	}
	if *count_data_from_File == true && *file != "" {
		LoadSettings()
		dbconn()
		RedisInit()
		CountDataFromFilev2(*file,*force_mode)
	}
	if *file_cache_counters == true && *file != "" {
		LoadSettings()
		dbconn()
		RedisInit()
		FileCacheCounters(*file, *force_mode)
	}

	if *initializeTables == true {
		LoadSettings()
		dbconn()
		InitializeTable(settings.SqlStorageTable, SQL_TABLE_STORAGE, force_mode)
		InitializeTable(settings.SQLBlacklistsDomainTable, SQL_TABLE_TYPE_DOMAINS, force_mode)
		InitializeTable(settings.SQLBlacklistsMxTable, SQL_TABLE_TYPE_MX, force_mode)
		InitializeTable(settings.SQLBlacklistsNameTable, SQL_TABLE_TYPE_NAMES, force_mode)
		InitializeTable(settings.SQLBlacklistsTable, SQL_TABLE_TYPE_EMAILS, force_mode)
		InitializeTable(settings.SQLOpenersTable, SQL_TABLE_OPENAI, force_mode)
		InitializeTable(settings.SQLClickersTable, SQL_TABLE_CLICKAI, force_mode)
		InitializeTable(settings.SQLDeliveriesTable, SQL_TABLE_DELIVERIES, force_mode)
		os.Exit(0)
	}
	if *cache_counters == true {
		LoadSettings()
		RedisInit()
		PopulateCounters(ReturnRDTable(settings.RedisBlacklistsEmailsTable), ReturnRDTable(CACHELIST_EMAILSBL_COUNT), *force_mode)
		PopulateCounters(ReturnRDTable(settings.RedisBlacklistsDomainTable), ReturnRDTable(CACHELIST_DOMAINSBL_COUNT), *force_mode)
		PopulateCounters(ReturnRDTable(settings.RedisBlacklistsMXTable), ReturnRDTable(CACHELIST_MXBL_COUNT), *force_mode)
		PopulateCounters(ReturnRDTable(settings.RedisBlacklistsNameTable), ReturnRDTable(CACHELIST_NAMESBL_COUNT), *force_mode)
		PopulateCounters(ReturnRDTable(settings.RedisDeliveryTable), ReturnRDTable(CACHELIST_DELIVERIES_COUNT), *force_mode)
		PopulateCounters(ReturnRDTable(settings.RedisOpenersTable), ReturnRDTable(CACHELIST_OPENERS_COUNT), *force_mode)
		PopulateCounters(ReturnRDTable(settings.RedisClickersTable), ReturnRDTable(CACHELIST_CLICKERS_COUNT), *force_mode)
	}
	if *load == true {
		LoadToRedis()
		os.Exit(0)
	}
	if *extract == true {
		if *file != "" {
			fmt.Printf("Got file: %s for extract operations!\n", *file)
			ExtractDump(*file, *continue_extract)
		} else {
			fmt.Printf("You have to specify --file for that operation!\n")
		}
	}

	if search != "" {
		RedisInit()
		fmt.Printf("Searching for %s\n", search)
		val, em, err := redisdb.HScan(ReturnRDTable(settings.RedisTable), 0, search, 10).Result()
		if err != nil {
			panic(err)
		}
		fmt.Printf("Found: %s\n", val)
		fmt.Printf("Found: %s\n", em)
		os.Exit(0)
	}
}
