package main


import (
	_ "github.com/go-sql-driver/mysql"
        "bytes"
        "net/http"
        "os"
        "encoding/json"
        "fmt"
        "time"
        "github.com/jmoiron/sqlx"
        _ "github.com/lib/pq"
        "gopkg.in/ini.v1"
)

var (
        mysqldb          *sqlx.DB
        MySQL_HOST  string
        MySQL_USER  string
        MySQL_DBAH  string
        MySQL_PASS  string
        MySQL_PORT  string
        APP_STORURL string
        APP_STORKEY string
)

type LaravelConfig struct {
        DB_HOST     string
        DB_DATABASE string
        DB_USERNAME string
        DB_PASSWORD string
        DB_PORT     string
        APP_STORURL string
        APP_STORKEY string
}


func main() {
fmt.Printf("Initalizing...\n")
if _, err := os.Stat("../.env"); err == nil {
LoadLaravelConfig("../.env")
// hardbounces
// select email from blacklists where external = 1
MigrateSQL(1,"select email as val, reason from blacklists where external = 1","Catched in parser")
// blacklists added manually
MigrateSQL(2,"select email as val, reason from blacklists where external IS NULL","Added manually")
// aditional type from ses (complains)
MigrateSQL(4,"select email as val, reason from blacklists where external = 0","Complain from SES")
// abuse reports
MigrateSQL(3,"select email as val, reason from blacklist_abuse","Abuse report")
// domains blacklist
MigrateSQL(7,"select domain as val, reason from blacklist_domains","Blocked domains")
// names blacklist
MigrateSQL(9,"select name as val, reason from blacklist_names","Blocked names")
// mx blacklist
// will do different function for it
MigrateSQL(11,"select record as val, reason from blacklist_mx","Blocked mx")

} else {
fmt.Printf("We cannot find lavonelis configuration file!\n")
}
}

func SubmitToApi(typea int,val []string, reason string) bool {
url := fmt.Sprintf("%sapi/v1/blacklists/set/%d",APP_STORURL,typea)
timeout := 2 // maximum of 2 secs
        ApiClient := http.Client{ 
                Timeout: time.Second * time.Duration(timeout),
        }
jsonas, err := json.Marshal(val)
req, err := http.NewRequest(http.MethodPost, url, bytes.NewBuffer(jsonas))
        if err != nil {
                fmt.Printf("err1: %s\n",err)
        }
req.Header.Set("Authorization", APP_STORKEY)
req.Header.Set("Reason",reason)
res, getErr := ApiClient.Do(req)
        if getErr != nil {
                fmt.Printf("This is critical error, we are unable to reach storage api http service: %v\n",err)
                os.Exit(1)
        }
fmt.Printf("I've got respond: %s\n",res.Status)
if res.StatusCode > 0 && res.StatusCode < 404 {
if res.StatusCode == 200 {
return true
}
} else {
fmt.Printf("Critical error, the storage returns invalid status code, please investigate the situation!\n")
os.Exit(1)
}
return false

}

func MigrateSQL(typea int, sql_query string, reason string) {
	dbconn()
	q, args, err := sqlx.In(sql_query) //creates the query string and arguments
	if err != nil {
		fmt.Printf("I've tried to crush sql with stupid sql query and it did it, it crashed :(... %s\n", err)
	}
	rows, err := mysqldb.Query(q, args...)
	if err != nil {
		fmt.Printf("Crashed again, oh no damn shit with it... %s\n", err)
	}
        var records []string          
        count := 0
        total_count := 0
	for rows.Next() {
		var val string
		var rsn string
		if err := rows.Scan(&val, &rsn); err != nil {
			fmt.Printf("Failing to set redis data for: %s %d\n", val, typea)
			continue
		}
                fmt.Printf("Processing type: %d number %d\r",typea,total_count)
                records = append(records,val)
                if count >= 10000 {
                SubmitToApi(typea,records,reason)
                count = 0
                records = nil
                }
                count++
                total_count++
		// here we should set the appropriate entries in redis
	//	redisdb.HSet(ReturnRDTable(redis_table), val, typea)
//                SubmitToApi(typea,val,reason)
	}
        SubmitToApi(typea,records,reason)
        fmt.Printf("\nDone processing type: %d!\n",typea)

}

// LoadLaravelConfig - reads laravel environment file configuration to the runtime variables
func LoadLaravelConfig(path string) {
        configfile := path
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
        APP_STORURL = cfg.Section("").Key("APP_STORURL").String()
        APP_STORKEY = cfg.Section("").Key("APP_STORKEY").String()
        fmt.Printf("We have done reading laravel environment configuration file with these specs: {host:%s,db:%s,user:%s,port:%s}\n", MySQL_HOST, MySQL_DBAH, MySQL_USER, MySQL_PORT)
        return
}

func dbconn() {

        if mysqldb == nil {
                //      db, err := sql.Open("mysql", MySQL_USER+":"+MySQL_PASS+"@tcp("+MySQL_HOST+":"+MySQL_PORT+")/"+MySQL_DBAH)
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
                fmt.Printf("Unable to ping the MySQL connection, it's lost!! Reconnecting...\n")
                db, err := sqlx.Connect("mysql", fmt.Sprintf("%s:%s@tcp(%s:%s)/%s", MySQL_USER, MySQL_PASS, MySQL_HOST, MySQL_PORT, MySQL_DBAH))
                if err != nil {
                        fmt.Printf("Got error then tried to contact to mysql server %s\n", err)
                        return
                }
                fmt.Printf("Reconnection was successfull!\n")
                mysqldb = db
        }
        return
}
