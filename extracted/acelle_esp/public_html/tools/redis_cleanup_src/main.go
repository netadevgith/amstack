package main

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"reflect"

	"github.com/go-redis/redis"
	"github.com/go-sql-driver/mysql"
	"github.com/jmoiron/sqlx"
	_ "github.com/lib/pq"
	ini "gopkg.in/ini.v1"
)

var (
	DEBUG   = 1
	Version string
	Build   string
	Commit  string
	// settings that load on the application start
	//	settings    Settings
	ProgramPath string
	Log         *log.Logger
	MySQL_HOST  string
	MySQL_USER  string
	MySQL_DBAH  string
	MySQL_PASS  string
	MySQL_PORT  string
	//      MySQLDBInterface
	// Dynamic variables
	mysqldb    *sqlx.DB
	redisdb    *redis.Client
	Campaigns  []Campaign_Row
	Exceptions []string
)

func main() {
	Exceptions = []string{"cache", ""}
	fmt.Printf("Redis cleaner Version %s (%s) Build %s\n", Version, Commit, Build)
	LoadSettings()
	RedisInit()
	GetCampaigns()
	WalkRedisCampaigns()
//        CleanOldTrackings() // should be enabled on most normal deployments
	//ExamineArchivedCampaign("5cc601d33388f")
}

type CampaignRedisObj struct {
	Id   int    `json:"id"`
	Uid  string `json:"uid"`
	Name string `json:"name"`
}

func WalkRedisCampaigns() {
	RedisInit()
	keys, err := redisdb.Keys("5*").Result()
	if err != nil {
		fmt.Printf("Unable to retrieve the keys from redis backend\n")
	}
	for _, key := range keys {
		val, err := redisdb.Get(key).Result()
		if err != nil {
			continue
		}
		CampObj := CampaignRedisObj{}
		if err := json.Unmarshal([]byte(val), &CampObj); err != nil {
			continue
		}
		if CampObj.Uid == "" {
			continue
		}
		ExamineArchivedCampaign(CampObj.Uid)
		//fmt.Printf("Got valid campaign json from redis backend %s -> %v\n", key, CampObj)

		//fmt.Println(key)
	}
}

func failOnError(err error, msg string) {
	if err != nil {
		log.Fatalf("%s: %s", msg, err)
	}
}

type Campaign_Row struct {
	Id         int      `db:"id"`
	Uid        string   `db:"uid"`
	Name       string   `db:"name"`
	Status     string   `db:"status"`
	Created_at NullTime `db:"created_at"`
	Updated_at NullTime `db:"updated_at"`
	Del_date   NullTime `db:"del_date"`
	Archived   NullTime `db:"archived"`
}

type TrackingLogItem struct {
	Trackid string
	Server  string
	Campuid string
	Suburl  string
	Test    bool
	Opened  bool
	Clicked bool
}

func CleanOldTrackings() {
Data, err := redisdb.HGetAll("tracking_logs").Result()
	if err != nil {
		return
	}
	for id, val := range Data {
        // unserialize tracking log the, check if campaign uid does not exist, make some cleanup then :)
         TrackObj := TrackingLogItem{}
         if err := json.Unmarshal([]byte(val), &TrackObj); err != nil { 
                        continue
         }
         if DoExistCampaignInMysql(TrackObj.Campuid) == false {
         fmt.Printf("We detected that campaign: %s does not exist, and tracking log: %s that belongs to this campaign, can be deleted! ... ",TrackObj.Campuid,id)
         res, err := redisdb.HDel("tracking_logs",id).Result()
         if err == nil && res == 1 {
          fmt.Printf("Deleted\n")
          } else {
          fmt.Printf("Unable to delete\n")
          }
//         break
         }
//			Idas, err := strconv.ParseInt(id, 0, 64)
        } // end for
}

func DoExistCampaignInMysql(uid string) bool {
	for _, row := range Campaigns {
		if row.Uid == uid {
			return true
		}
	}
	return false
}

func ReturnCampainObj(uid string) Campaign_Row {
	returnas := Campaign_Row{}
	for _, row := range Campaigns {
		if row.Uid == uid {
			returnas = row
		}
	}
	return returnas
}

func CompletelyWanish(uid string) {
	if uid != "" && len(uid) > 12 {
		// clean procedure here
		fmt.Printf("Wanishing data for campaign: %s\n", uid)
		keys, err := redisdb.Keys(fmt.Sprintf("*%s*", uid)).Result()
		if err != nil {
			fmt.Printf("Unable to retrieve the keys from redis backend\n")
			return
		}
		for _, key := range keys {
			exists, err := redisdb.Exists(key).Result()
			if err == nil {
				if exists == 1 {
					fmt.Printf("Deleting key: %s from redis backend...\n", key)
					_, err = redisdb.Del(key).Result()
					if err != nil {
						fmt.Printf("Unable to delete key: %s from redis backend\n", key)
					}
				}
			}
		}
	}
}

func DealWithArchivedCampaign(uid string) {
	DelKeys := []string{"%s_undelivered_val", "%s_undelivered_data", "campaign_%s_subscribers", "campaign_%s_static"}
	fmt.Printf("We do archive some keys for campaign: %s in redis backend\n", uid)
	for _, key := range DelKeys {
		exists, err := redisdb.Exists(fmt.Sprintf(key, uid)).Result()
		if err == nil {
			if exists == 1 {
				fmt.Printf("Deleting key: %s from redis backend...\n", fmt.Sprintf(key, uid))
				_, err = redisdb.Del(fmt.Sprintf(key, uid)).Result()
				if err != nil {
					fmt.Printf("Unable to delete key: %s from redis backend\n", fmt.Sprintf(key, uid))
				}
			}
		}
	}
}

func ExamineArchivedCampaign(uid string) {
	camp := ReturnCampainObj(uid)
	if camp.Id > 0 {
		fmt.Printf("Campaign exists: %v\n", camp)
		// we will check here if campaign is archived and delete static and dynamic subscribers where can be some
		// if archived is not null
		if camp.Archived.Valid {
			//fmt.Printf("Campaign: %s must be checked for achiving purposes\n", uid)
			DealWithArchivedCampaign(uid)
		}
	} else {
		fmt.Printf("Campaign: %s was not found on the mysql and should be cleaned from redis backend ASAP!!!\n", uid)
		// do clean here
		CompletelyWanish(uid)
	}

}

func GetCampaigns() {
	dbconn()
	err := mysqldb.Ping()
	if err != nil {
		fmt.Printf("Got error while tried to ping mysql server\n")
	}
	Campaigns = []Campaign_Row{}
	err = mysqldb.Select(&Campaigns, "SELECT id,uid,name,status,created_at,updated_at,del_date,archived from campaigns")
	if err != nil {
		fmt.Printf("Got problems: %s", err)
	}
	// fmt.Printf("fetching the campaigns...\n")
	count := 0
	for _, row := range Campaigns {
		count++
		fmt.Printf("%s ", row.Uid)

	}
	if count == 0 {
		fmt.Printf("\nThis deployment has no campaigns at all :(\n")
		os.Exit(0)
	} else {
		fmt.Printf("\nGot %d campaigns loaded to the memory\n", count)
	}
}

func LoadSettings() {
	Pathas, err := filepath.Abs(filepath.Dir(os.Args[0]))
	if err != nil {
		ProgramPanic(err)
	}
	if DEBUG > 0 {
		fmt.Printf("Program is running from: %s directory\n", Pathas)
	}
	ProgramPath = Pathas
	LoadLaravelConfig("../.env")

	var logpath = ProgramPath + "/redis-cleanup.log"
	var file, err1 = os.OpenFile(logpath, os.O_RDWR|os.O_CREATE|os.O_APPEND, 0666)
	if err1 != nil {
		ProgramPanic(err1)
	}
	Log = log.New(file, "", log.LstdFlags|log.Lshortfile)
	Logas("LogFile : " + logpath)

}

func RedisInit() {
	redisdb = redis.NewClient(&redis.Options{Addr: "localhost:6379", Password: "", DB: 0})
}

type LaravelConfig struct {
	DB_HOST     string
	DB_DATABASE string
	DB_USERNAME string
	DB_PASSWORD string
	DB_PORT     string
}

// LoadLaravelConfig - reads laravel environment file configuration to the runtime variables
func LoadLaravelConfig(path string) {
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

func dbconn() {

	if mysqldb == nil {
		//db, err := sql.Open("mysql", MySQL_USER+":"+MySQL_PASS+"@tcp("+MySQL_HOST+":"+MySQL_PORT+")/"+MySQL_DBAH)
		db, err := sqlx.Connect("mysql", fmt.Sprintf("%s:%s@tcp(%s:%s)/%s", MySQL_USER, MySQL_PASS, MySQL_HOST, MySQL_PORT, MySQL_DBAH))
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
		fmt.Printf("Unable to ping the MySQL connection, it's lost!!\n")
		db, err := sqlx.Connect("mysql", fmt.Sprintf("%s:%s@tcp(%s:%s)/%s", MySQL_USER, MySQL_PASS, MySQL_HOST, MySQL_PORT, MySQL_DBAH))
		if err != nil {
			fmt.Printf("Got error then tried to contact to mysql server %s\n", err)
			return
		}
		mysqldb = db
	}
	return
}

func ProgramPanic(err error) {
	Logas("Program panic: %s\n", err)
	panic(err)
}

func Logas(text string, a ...interface{}) {
	Log.Printf(text, a...)
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
