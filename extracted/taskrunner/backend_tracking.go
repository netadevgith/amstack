package main

import (
	"log"
	"net"
	"strings"
	"time"

	//	"log"
	"fmt"
	// "database/sql"
	// _ "github.com/go-sql-driver/mysql"
)

var (
	BLOCKED_IPS []string
	BLOCKED_URLS []string
	BLOCKED_USERAGENTS []string
)

func RedisSet(key, val string) error {
	err := redisdb.Set(key, val, 0).Err()
	return err
}

func RedisHSet(key,key2,val string) error {
	err := redisdb.HSet(key,key2,val).Err()
	return err
}

func InsertSQL(query string,values []string) (int64,error) {
	dbconn()
	params := strings.Join(values,"','")
	sql := fmt.Sprintf("%s VALUES('%s')",query,params)
	loggers.debug.Printf("Generated sql: %s\n",sql)
	res, err := mysqldb.Exec(sql)
	if err == nil {
		lastId, err := res.LastInsertId()
		return lastId,err
	}
	loggers.debug.Printf("MySQL error: %s\n",err)
    return 0,err
}

func InsertTrackingOpen(data TrackingLog, remote RemoteDetails) error {
	data_now := DateNow()
	_, err := InsertSQL("INSERT INTO open_logs (message_id,ip_address,user_agent,created_at,updated_at)",[]string {data.TrackId,remote.RemoteIP,remote.UserAgent,data_now,data_now})
	return err
}

func InsertTrackingClick(data TrackingLog, remote RemoteDetails, url string) error {
	data_now := DateNow()
	_, err := InsertSQL("INSERT INTO click_logs (message_id,url,ip_address,user_agent,created_at,updated_at)",[]string {data.TrackId,url,remote.RemoteIP,remote.UserAgent,data_now,data_now})
	return err
}

func InsertUnsubscribeLog(data SqlTracking, data2 TrackingLog, remote RemoteDetails) error {
	data_now := DateNow()
	_, err := InsertSQL("INSERT INTO unsubscribe_logs (message_id,ip_address,user_agent,created_at,updated_at)",[]string {data2.TrackId,remote.RemoteIP,remote.UserAgent,data_now,data_now})
	if err != nil {
		return err
	}
	err = SetUnsubscribeStatus(data.SubscriberUid)
	if err != nil {
		return err
	}
	return err
}

func StripSpecCharsFromLink(link string) string {
	return strings.Replace(link, "&amp;", "&", -1)
}

func SetUnsubscribeStatus(uid string) error {
	dbconn()
	sql := fmt.Sprintf("UPDATE subscribers set status = 'unsubscribed' where uid = '%s'",uid)
	loggers.debug.Printf("Generated sql: %s\n",sql)
	_, err := mysqldb.Exec(sql)
	if err != nil {
		loggers.debug.Printf("MySQL error: %s\n",err)
	}
    return err
}

type SqlTracking struct {
	Email 			string	 `db:"email"`
	SubscriberUid   string     `db:"subscriber_uid"`
	MailList 		string     `db:"maillist"`
	MailListUid     string `db:"maillist_uid"`
}

func GetReverse(ip string) string {
	rev, _ := net.LookupAddr(ip)
	return strings.Join(rev," ")
}

func DateNow() string {
	now := time.Now()
	return fmt.Sprintf(now.Format("2006-01-02 15:04:05")) //  $date_added = date("Y-m-d H:i:s");
}

func GetTrackingInfo(track_id string) (SqlTracking, error) {
	query := fmt.Sprintf("select email,subscribers.uid as subscriber_uid,name as maillist,mail_lists.uid as maillist_uid from tracking_logs inner join subscribers on tracking_logs.subscriber_id = subscribers.id inner join mail_lists on subscribers.mail_list_id = mail_lists.id  where message_id = '%s'",track_id)
	dbconn()
	Item := SqlTracking{}
	err := mysqldb.Get(&Item, query)
	return Item,err
}

func UrlBlocked(url string) bool {
	if StringArrayContains(BLOCKED_URLS,url) {
		return true
	}
	return false
}

func UserAgentBlocked(agent string) bool {
	if StringArrayContains(BLOCKED_USERAGENTS,agent) {
		return true
	}
	return false
}

func IpBlocked(ip string) bool {
	if StringArrayContains(BLOCKED_IPS, ip) {
		return true
	}
	return false
}

func TrackingInternalLoop() {
	for {
		log.Printf("Tracking internal loop cycle refresh\n")
		// blocked urls
        tmpfile, err := readLines(ProgramPath+"/blocked_urls")
		if err == nil {
			BLOCKED_URLS = tmpfile
			log.Printf("Loaded list blocked urls: %#v",BLOCKED_URLS)
		} else {
			log.Printf("Unable to load blocked urls file list: %s\n",err)
		}
		// blocked ips
		tmpfile, err = readLines(ProgramPath+"/blocked_ips")
		if err == nil {
			BLOCKED_IPS = tmpfile
			log.Printf("Loaded list blocked ips: %#v",BLOCKED_IPS)

		} else {
			log.Printf("Unable to load blocked ips file list: %s\n",err)
		}
		// blocked user agents
		tmpfile, err = readLines(ProgramPath+"/blocked_useragents")
		if err == nil {
			BLOCKED_USERAGENTS = tmpfile
			log.Printf("Loaded list blocked user agents: %#v",BLOCKED_USERAGENTS)
		} else {
			log.Printf("Unable to load blocked user agents file list: %s\n",err)
		}
		time.Sleep(60*time.Second)
	}
}