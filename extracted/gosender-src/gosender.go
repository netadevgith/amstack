/*
Requirements: go get -u github.com/go-redis/redis;
go get -u github.com/go-sql-driver/mysql
go get -u github.com/gookit/color
go get -u mvdan.cc/xurls/cmd/xurls
go get -u github.com/sendgrid/sendgrid-go
go get -u github.com/aws/aws-sdk-go/aws
go get -u gopkg.in/gomail.v2

LASTMODDATE: 2023.01.16

Version history:
3.4.4 - Added ability to generate random symbols {rndsym[30,40]}, later this can be implemented https://github.com/chrismcguire/gobberish/blob/master/gobberish.go
3.4.3 - Added abillity to log all campaign tracking logs inserts into redisdb tracking_logs table, added ability to use {EMAIL} tag in headers
3.4.2 - Added support for custom tracking and send address per server
3.4.1 - Added support for https links
3.4   - Deferred emails handling implementation, that handles different queue, some code cleanup
3.3.6 - Implementation of randomness in campaign header, subject and name functions {date}, {rndnum[min,max]}, {rndstr[min,max]}
3.3.5 - Implemented support for laravel configuration include and set this configuration as the default
3.3.4 - Implemented custom fields in contact
3.3.2 - Implementation of random html tags
3.3.1 - Implementation of custom headers
3.3   - Implementation of remote storage external tracking links/images and other stuff
3.2.4 - Bugfix release, fixing url regexp detection and unexpectedly results, but needs further testing
3.2.3 - Bugfix release, fixing concurrently threads end waiting issue, fixed random tracking_log ids generation by using unix time stamp nanoseconds from epoch
3.2.2 - Implemented Storage Cloud API Blacklists support for the sender :)
3.2.1 - Fixed {TAGS} in html rewrite
3.2 - Implemented sendgrid support
3.1 - Implemented 3 tracking types, merged sesender altogether

*/
package main

import (
	"bytes"
	"crypto/tls"
	"database/sql"
	"encoding/base64"
	"encoding/json"
	"flag"
	"fmt"
	"html/template"
	"io/ioutil"
	"log"
	"math/rand"
	"net"
	"net/http"
	"net/mail"
	"net/smtp"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"
	"errors"

	"github.com/aws/aws-sdk-go/aws"
	"github.com/aws/aws-sdk-go/aws/awserr"
	"github.com/aws/aws-sdk-go/aws/credentials"
	"github.com/aws/aws-sdk-go/aws/session"
	"github.com/aws/aws-sdk-go/service/ses"
	"github.com/go-redis/redis"
	_ "github.com/go-sql-driver/mysql"
	"github.com/gookit/color"
	sendgrid "github.com/sendgrid/sendgrid-go"
	sghelper "github.com/sendgrid/sendgrid-go/helpers/mail"
	gomail "gopkg.in/gomail.v2"
	"mvdan.cc/xurls/v2"
        "github.com/spf13/viper"
)

const (
	on_timeout_exit       = false
	experimental_features = true
	deferred_handling_plugin = true
)

var (
        Version = "3.4.4"
        Build string
        Commit string
	BENCHMARK = 0
	DEBUG     = 0
        //                       ############ EXPERIMENTAL FEATURES ###############
        // FIXME this should be implemented in config
        REPORT_PREFIX = "report"
        HTTPS_LINKS = false
        CUST_SERVER_TRACKING = true // server tracking per sending server
        CAMPAIGN_LOGGING = true // enable write campaign log with redis uuids, for later inspection
        //                       ############ END OF EXPERIMENTAL FEATURES ########
	// there are the dynamic variables
        campLogfile *os.File
	test       bool
	campaign   string
	ServerType string
	accesskey  string
	secretkey  string
	region     string
	smtphost   string
	smtpport   string
	smtpspeed  int
	deployment string
	nodone     bool
	username   string
	password   string
	ssl        bool
	// static variables
	auth        smtp.Auth
	redisdb     *redis.Client
	settings    Settings
	Log         *log.Logger
	mxblacklist MXBlacklistas
	svc         *ses.SES
        // custom headers implementation
        CUSTOM_HEADERS_TRACKING bool
        CUSTOM_HEADERS_ENABLED bool
        CUSTOM_HEADERS_ENTRIES map[string]string
        // random html tags implementation
        babbler Babbler
)

func init() {
        rand.Seed(time.Now().UnixNano())
        DetectProgramPath()
}

func main() {
	ReadSettings()
	Logas("sender v%s - Backend mail sender for acelle project\n",Version)
	fmt.Printf("sender v%s - Backend mail sender for acelle project\n" +
		"Bug reports, feature requests\n",Version)
	// here we will initialize the program parameter flags
	initiate := flag.Bool("send", false, "Start sending email")
	flag.BoolVar(&test, "test", false, "(optional) Run internal script test mechanism")
	flag.StringVar(&campaign, "campuid", "", "Campaign UID to search data in Redis")
	flag.StringVar(&ServerType, "type", "smtp", "Server type of the sending mechanism")
	flag.StringVar(&smtphost, "smtphost", "", "Destination mail server host")
	flag.StringVar(&smtpport, "smtpport", "2525", "Destination mail server port")
	flag.StringVar(&accesskey, "accesskey", "", "Amazon SES API Access Key")
	flag.StringVar(&secretkey, "secretkey", "", "Amazon SES API Secret Key")
	flag.StringVar(&region, "region", "", "AWS Region used for sending emails from")
	flag.IntVar(&smtpspeed, "smtpspeed", 1000000, "Destination mail server sending speed (in microseconds)")
	//	_ = flag.String("ssl", "", "Deprecated just for compatibility reasons") // FIXME remove in future
	flag.StringVar(&deployment, "app", "", "Deployment app (postfix parser requirement)")
	// FIXME we need to replace nodone with --wait, waits in cycle for new subscribers in redis backend
	flag.BoolVar(&nodone, "nodone", false, "Does not change status to done")
	// few more additions to support authenticated smtp
	flag.BoolVar(&ssl, "ssl", false, "(optional) Use ssl mode")
	flag.StringVar(&username, "username", "user", "Username for smtp authentification")
	flag.StringVar(&password, "password", "pass", "Password for smtp authentification")
	flag.Parse()
        // new random generator
        babbler = NewBabbler()
	if (*initiate == true || test == true) && campaign != "" && (smtphost != "" || accesskey != "") {
		RedisInit()
                if CAMPAIGN_LOGGING {
                        InitializeCampaignLogging()
                }
	        // licensing block
        	if EnabledLicensing() {
	           testcert()
	        } else {
	           fmt.Printf("Licensing volume is not enabled\n")
	        }
	        // end of licensing block
                LoadCustomHeaders()
		if ServerType == "amazon-api" {
			SESInit()
		}
		if ServerType == "sendgrid-api" {
			SendGridInit()
		}
		if DEBUG > 0 {
			fmt.Printf("Parameters: \n")
			fmt.Printf("send: %v\n", *initiate)
			fmt.Printf("test: %v\n", test)
			fmt.Printf("ServerType: %s\n", ServerType)
			fmt.Printf("campuid: %s\n", campaign)
			fmt.Printf("smtphost: %s\n", smtphost)
			fmt.Printf("smtpport: %s\n", smtpport)
			fmt.Printf("accesskey: %s\n", accesskey)
			fmt.Printf("secretkey: %s\n", secretkey)
			fmt.Printf("region: %s\n", region)
			fmt.Printf("smtpspeed: %d\n", smtpspeed)
			fmt.Printf("app: %s\n", deployment)
			fmt.Printf("nodone: %v\n", nodone)
			fmt.Printf("ssl: %v\n", ssl)
			fmt.Printf("username: %s\n", username)
			fmt.Printf("password: %s\n", password)
		}
		camp, err := getCampaign(campaign)
		if err != nil {
			fmt.Printf("Error while getting the campaign info: %s\n", err)
			Logas("Error while getting the campaign info: %s\n", err)
			ProgramPanic(err)
		}
		MainLoop(camp)
		defer redisdb.Close()
	} else {
		fmt.Fprintf(os.Stderr, "Usage of %s:\n", os.Args[0])
		flag.PrintDefaults()
	}

}


type CustomHeadersRedis struct {
Tracking_enabled string `json:"tracking_enabled"`
Custom_headers map[string]string `json:"custom_headers"`
Custom_headers_raw string `json:"custom_headers_raw"`
}

func LoadCustomHeaders() {
 done, err := redisdb.Exists(campaign + "_headers").Result()
        if err == nil {
                if done == 1 {
					
                        // load headers here
                        CustHead := CustomHeadersRedis{}
                        raw, err := redisdb.Get(campaign + "_headers").Result()
                        if err == nil {
                        err = json.Unmarshal([]byte(raw),&CustHead)
                           if err == nil {
                           count := 0
                           CUSTOM_HEADERS_ENTRIES = make(map[string]string)
                           for key,val := range CustHead.Custom_headers {
                             count++
                             CUSTOM_HEADERS_ENTRIES[key] = val
                             }
                           if CustHead.Tracking_enabled == "1" {
                           CUSTOM_HEADERS_TRACKING = true
                           }
                           if count > 0 {
                           CUSTOM_HEADERS_ENABLED = true
                           }
                           return
                           } else {
                           fmt.Printf("Error in unmarshaling the json for custom campaign headers %s\n",err)
                           }
                        }
                }
        }
        fmt.Printf("No custom headers found\n")
        CUSTOM_HEADERS_TRACKING = true
        CUSTOM_HEADERS_ENABLED = false
        return
}

func ThreadWaitTimeout(wg *sync.WaitGroup) {
	Logas("Waiting for last threads to finish...\n")
	c := make(chan struct{})
	go func() {
		defer close(c)
		wg.Wait()
	}()
	select {
	case <-c:
		Logas("Threads finished normally")
		os.Exit(0)
	case <-time.After(10 * time.Second):
		Logas("Threads finished by timeout")
		os.Exit(0)
	}
}

// MainLoop - sending loop
func MainLoop(camp Campaign) {
	var wg sync.WaitGroup
	if test == true {
		fmt.Printf("JUST TESTING! (SIMULATION MODE)...\n")
	}
	fmt.Printf("----------> STARTING SENDING --- Campaign: %s ID: %s <----------------------\n", camp.Name, camp.Uid)
	fmt.Printf("Sending the emails trough %s:%s with speed %d of microseconds...\n", smtphost, smtpport, smtpspeed)
	Logas("----------> STARTING SENDING --- Campaign: %s (%s) <----------------------\n", camp.Name, camp.Uid)
	Logas("Sending the emails trough %s:%s with speed %d of microseconds...\n", smtphost, smtpport, smtpspeed)
	//necessary_count := 0
	if test == false {
		ChangeCampaignStatus(camp.Uid, "sending")
	}
	for {
		t0 := time.Now()
		// check if whole campaign is paused, if paused then sleep for two seconds
		for {
			if IfPaused(camp.Uid) == false || test == true {
				break
			}
			if DEBUG > 0 {
				fmt.Printf("Waiting for paused %s (%s) campaign\n", camp.Name, camp.Uid)
			}
			//Logas("Waiting for paused %s (%s) campaign\n", camp.Name, camp.Uid)
			Logas("Waiting for paused %s (%s) campaign\n", camp.Name, camp.Uid)
			time.Sleep(2 * time.Second)
		}
		sub, err := GetRandomSubscriber(camp.Uid, test, &wg)
		if err != nil {
			if DEBUG > 0 {
				fmt.Printf("Unable to get subscriber from GetRandomSubscriber function... Continuing...\n")
			}
			Logas("Unable to get subscriber from GetRandomSubscriber function... Continuing...\n")
			continue
		}

        // deferred handling implementation
		if deferred_handling_plugin {
			if DeferredHandlingEnabledForCampaign(camp.Uid) && ReturnDeferredCount(camp.Uid) > 0 && test == false {
				defsub, err2 := GetRandomDeferredSubscriber(camp.Uid, &wg)
				wait_time := GetDeferredWaitTime(camp.Uid)

				if GetCount(camp.Uid) >= wait_time && err2 == nil {

				if EmailValidator(defsub.Email) == true && CheckBlacklists(defsub.Email) == false && FinalCheckMXEmal(defsub.Email) == false {
					// we had to re-read the campaign info from the redis
					tmpcamp2, err3 := getCampaign(campaign)
					var camp2 Campaign
					if err3 == nil {
						camp2 = tmpcamp2
					}
					trackdomain2 := DetectTrackingDomain(camp2, defsub)
					parsed_html2 := GenerateCampaignTemplate(campaign, camp2.Html, defsub, trackdomain2, camp2.Tracktype)
					Req2 := NewRequest(camp2.From_email, camp2.From_name, defsub.Email, camp2.Subject, parsed_html2, defsub.Message_id)
					wg.Add(1)
					go SMTPSend(&wg, Req2)
					// cleanup
					trackdomain2 = ""
					parsed_html2 = ""
					Req2 = nil
				}
				DeferredIncreaseCounter(camp.Uid)
				//IncreaseCounter(camp.Uid)
				time.Sleep(time.Duration(smtpspeed) * time.Microsecond)
			}
		}
		}
		// deferred handling end

		//if EmailValidator(sub.Email) == true && CheckBlacklists(sub.Email) == false {
		// new implementation also checks the mx records
		if EmailValidator(sub.Email) == true && CheckBlacklists(sub.Email) == false && FinalCheckMXEmal(sub.Email) == false {
			// we had to re-read the campaign info from the redis
			tmpcamp, err2 := getCampaign(campaign)
			if err2 == nil {
				camp = tmpcamp
			}
			trackdomain := DetectTrackingDomain(camp, sub)
                        // tracking per sending server implementation 2022.09.09
                        if CUST_SERVER_TRACKING {
                           custSend, senda := GetServerSendAddress(smtphost)
                           custTrack, tracka := GetServerTracking(smtphost)
                           if custSend {
                                 camp.From_email = senda
                           }
                           if custTrack {
                                 trackdomain = tracka
                           }
                        }
			parsed_html := GenerateCampaignTemplate(campaign, camp.Html, sub, trackdomain, camp.Tracktype)
			Req := NewRequest(camp.From_email, camp.From_name, sub.Email, camp.Subject, parsed_html, sub.Message_id)
			// autopause support
			var avtopauzis int64 = 0
			avtopauzis, err = strconv.ParseInt(camp.AutoPause, 10, 64)
			if err == nil && avtopauzis > 0 && test == false {
				if DEBUG > 0 {
					Logas("We currently at autopause suspension at %d, currently is %d", avtopauzis, GetCount(camp.Uid))
				}
				if GetCount(camp.Uid)+1 >= avtopauzis {
					// do camapign pause here
					SetPaused(camp.Uid)
				}
			}
			// cia galima implementint multi-thread'a

			// If threads are on set the minimum speed, to lower system loads
			if smtpspeed < settings.MinSpeed {
				smtpspeed = settings.MinSpeed
			}
			wg.Add(1)
			go SMTPSend(&wg, Req)

			// cleanup
			trackdomain = ""
			parsed_html = ""
			Req = nil
		}
		IncreaseCounter(camp.Uid)
		//necessary_count++
		// free some resources
		// every 1000 subscribers we will free some memory of the system
		// if necessary_count > 999 {
		// 	debug.FreeOSMemory()
		// 	necessary_count = 0
		// }

		time.Sleep(time.Duration(smtpspeed) * time.Microsecond)
		if IfCancelled(camp.Uid) {
			fmt.Printf("Campaign %s ID: %s got cancelled from the frontend!\n", camp.Name, camp.Uid)
			Logas("Campaign %s (%s) got cancelled from the frontend!\n", camp.Name, camp.Uid)
			//ThreadWaitTimeout()
			os.Exit(0)
		}
		t2 := time.Now()
		if BENCHMARK > 0 {
			fmt.Printf("One cycle tick took: %s\n", t2.Sub(t0))
		}
	}
	// end of MainLoop
}

func Logas(text string, a ...interface{}) {
	if settings.Logging == true {
		Log.Printf(text, a...)
	}
}

func IfCancelled(uid string) bool {
	canceled, err := redisdb.Exists(uid + "_canceled").Result()
	if err == nil {
		if canceled == 1 {
			return true
		}
	}
	return false
}

func IfPaused(uid string) bool {
	paused, err := redisdb.Exists(uid + "_paused").Result()
	if err == nil {
		if paused == 1 {
			return true
		}
	}
	return false
}

func SetPaused(uid string) {
	_, err := redisdb.Set(uid+"_paused", "1", 0).Result()
	if err != nil {
		fmt.Printf("Some problem then trying to pause the campaign %s\n", uid)
	}
	return
}

func GetCount(uid string) int64 {
	stat, err := redisdb.Get(uid + "_counter").Int64()
	if err == nil {
		return stat
	}
	return 0
}

func IfTrackingDone(uid string) bool {
	done, err := redisdb.Exists("campaign_" + uid + "_trackingdone").Result()
	if err == nil {
		if done == 1 {
			return true
		}
	}
	return false
}

func BoolToInt(val bool) int {
	if val {
		return 1
	} else {
		return 0
	}
}

func EmailValidator(email string) bool {
	t0 := time.Now()
	regex := regexp.MustCompile("^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$")
	if regex.MatchString(email) == true {
		t2 := time.Now()
		if BENCHMARK > 0 {
			fmt.Printf("EmailValidator positive took: %s\n", t2.Sub(t0))
		}

		return true
	}

	t2 := time.Now()
	if BENCHMARK > 0 {
		fmt.Printf("EmailValidator negative took %s\n", t2.Sub(t0))
	}

	return false
}

func StorageApiCheckBL(record string) bool {
	timeout := 60 // maximum of 2 secs
	record = strings.ToLower(record)
	ApiClient := http.Client{
		Timeout: time.Second * time.Duration(timeout),
	}
	req, err := http.NewRequest(http.MethodGet, settings.StorageUrl+"api/v1/blacklists/check/"+record, nil)
	if err != nil {
		fmt.Printf("err1: %s\n", err)
	}
	req.Header.Set("Authorization", settings.StorageKey)
	res, getErr := ApiClient.Do(req)
	if getErr != nil {
		Logas("This is critical error, we are unable to reach storage api http service: %v\n", err)
		fmt.Printf("This is critical error, we are unable to reach storage api http service: %v\n", err)
		//ThreadWaitTimeout()
		os.Exit(1)
	}
	if DEBUG > 0 {
		fmt.Printf("I've got respond for bl: %s\n", res.Status)
	}
	if res.StatusCode > 0 && res.StatusCode < 404 {
		if res.StatusCode == 200 {
			return true
		}
	} else {
		Logas("Critical error, the storage returns invalid status code, please investigate the situation!\n")
		fmt.Printf("Critical error, the storage returns invalid status code, please investigate the situation!\n")
		//ThreadWaitTimeout()
		os.Exit(1)
	}
	return false

}

func CheckBlacklists(email string) bool {
	t0 := time.Now()
	// Storage API Implementation
	if settings.EnableStorageApi {
		if StorageApiCheckBL(email) {
			return true
		}
	}

	// check in "blacklists"
	val, err := redisdb.HExists("blacklists", email).Result()
	if err == nil {
		if val == true {
			if DEBUG > 0 {
				fmt.Printf("Backlist record: %s found in blacklists\n", email)
			}
			Logas("Backlist record: %s found in blacklists\n", email)
			return true
		}
	}
	// check in "blacklists_fast"
	val, err = redisdb.HExists("blacklists_fast", email).Result()
	if err == nil {
		if val == true {
			if DEBUG > 0 {
				fmt.Printf("Blacklist record %s found in blacklists_fast\n", email)
			}
			Logas("Blacklist record %s found in blacklists_fast\n", email)
			return true
		}
	}
	// check in "blacklist_abuse"
	val, err = redisdb.HExists("blacklist_abuse", email).Result()
	if err == nil {
		if val == true {
			if DEBUG > 0 {
				fmt.Printf("Blacklist record %s found in blacklist_abuse\n", email)
			}
			Logas("Blacklist record %s found in blacklist_abuse\n", email)
			return true
		}
	}
	// we have to split string and detect the user and the domain in email string
	components := strings.Split(email, "@")
	username, domain := components[0], components[1]
	// check in "blacklist_names" [0]
	if username != "" {
		if settings.EnableStorageApi {
			if StorageApiCheckBL(username) {
				return true
			}
		}
		val, err = redisdb.HExists("blacklist_names", username).Result()
		if err == nil {
			if val == true {
				if DEBUG > 0 {
					fmt.Printf("Blacklist record %s found in blacklist_names\n", username)
				}
				Logas("Blacklist record %s found in blacklist_names\n", username)
				return true
			}
		}
	}
	// check in "blacklist_domains" [1]
	if domain != "" {
		if settings.EnableStorageApi {
			if StorageApiCheckBL(domain) {
				return true
			}
		}
		val, err = redisdb.HExists("blacklist_domains", domain).Result()
		if err == nil {
			if val == true {
				if DEBUG > 0 {
					fmt.Printf("Blacklist record %s found in blacklist_domains\n", domain)
				}
				Logas("Blacklist record %s found in blacklist_domains\n", domain)
				return true
			}
		}
	}
	t2 := time.Now()
	if BENCHMARK > 0 {
		fmt.Printf("Blacklist check negative took %s\n", t2.Sub(t0))
	}
	return false
}

type Settings struct {
	MySQLHost          string
	MySQLDb            string
	MySQLPort          string
	MySQLUser          string
	MySQLPassword      string
	Logging            bool
//	Threaded           bool
	MinSpeed           int
	UseNewEngine       bool
	UseTrackingHeaders bool
        RandomHtmlTags     bool
	Algorithm          map[string]string
	TrackTag           string
	Debug              bool
	EnableStorageApi   bool
	StorageUrl         string
	StorageKey         string
        StorageTimeout     int
        EnableLinker       bool
        LinkerUrl          string
        LinkerKey          string
}

func ReadSettings() {
// NEW IMPL 2020.11.05
        dir, err := filepath.Abs(filepath.Dir(os.Args[0]))
        if err != nil {
                ProgramPanic(err)
        }

        configfile := dir + "/public_html/.env"
        fmt.Printf("Laravel Configuration should be in %s\n", configfile)
        // new implementation
        viper.SetConfigFile(configfile)
        viper.ReadInConfig()
        settings.MySQLHost = viper.GetString("DB_HOST")
        settings.MySQLDb = viper.GetString("DB_DATABASE")
        settings.MySQLPort = viper.GetString("DB_PORT")
        settings.MySQLUser = viper.GetString("DB_USERNAME")
        settings.MySQLPassword = viper.GetString("DB_PASSWORD")
        settings.Logging = viper.GetBool("GOSENDER_LOGGING")
        settings.MinSpeed = viper.GetInt("GOSENDER_MINSPEED")
        settings.UseNewEngine = viper.GetBool("GOSENDER_USENEWENGINE")
        settings.UseTrackingHeaders = viper.GetBool("GOSENDER_USETRACKINGHEADERS")
        settings.RandomHtmlTags = viper.GetBool("GOSENDER_RANDOMHTMLTAGS")
        settings.Algorithm = viper.GetStringMapString("GOSENDER_ALGORITHM")
        settings.TrackTag = viper.GetString("GOSENDER_TRACKTAG")
        settings.Debug = viper.GetBool("GOSENDER_DEBUG")
        settings.EnableStorageApi = viper.GetBool("APP_STORAGE")
        settings.StorageUrl = viper.GetString("APP_STORURL")
        settings.StorageKey = viper.GetString("APP_STORKEY")
        settings.StorageTimeout = viper.GetInt("GOSENDER_STORAGETIMEOUT")
        settings.EnableLinker = viper.GetBool("GOSENDER_ENABLELINKER")
        settings.LinkerUrl = viper.GetString("GOSENDER_LINKERURL")
        settings.LinkerKey = viper.GetString("GOSENDER_LINKERKEY")

        if settings.Debug {
                DEBUG = 1
        }
        if DEBUG > 0 {
                fmt.Printf("ENV: %#v\n",settings)
        }



	// create log facility
	if settings.Logging == true {
		var logpath = dir + "/gosender.log"
		var file, err1 = os.OpenFile(logpath, os.O_RDWR|os.O_CREATE|os.O_APPEND, 0666)

		if err1 != nil {
			ProgramPanic(err1)
		}
		log.SetOutput(file)

		Log = log.New(file, "", log.LstdFlags|log.Lshortfile)
		Logas("LogFile : " + logpath)
	}

	return

}

// ChangeCampaignStatus - Mark the campaign as done in mysql and redis backends
func ChangeCampaignStatus(uid, status string) {
	if uid == "" {
		return
	}
	STATUS := status
	db, err := sql.Open("mysql", settings.MySQLUser+":"+settings.MySQLPassword+"@tcp("+settings.MySQLHost+":"+settings.MySQLPort+")/"+settings.MySQLDb)
	if err != nil {
		ProgramPanic(err)
	}
	defer db.Close()
	err = db.Ping()
	if err != nil {
		ProgramPanic(err)
	}
	updeitas := `UPDATE campaigns set status = ? WHERE uid = ?`
	db.Exec(updeitas, STATUS, uid)
	// if err != nil {
	// 	ProgramPanic(err)
	// }

	val, err := redisdb.Get(uid).Result()
	if err != nil {
		ProgramPanic(err)
	}
	var dat map[string]interface{}
	if err := json.Unmarshal([]byte(val), &dat); err != nil {
		ProgramPanic(err)
	}
	if DEBUG > 0 {
		fmt.Printf("Current campaign %s status: %s\n", uid, dat["status"])
		fmt.Printf("Setting campaign %s status to: %s\n", uid, STATUS)
	}
	Logas("Setting campaign %s status to: %s\n", uid, STATUS)
	dat["status"] = STATUS
	json, err := json.Marshal(dat)
	if err != nil {
		ProgramPanic(err)
	}

	err = redisdb.Set(uid, json, 0).Err()
	if err != nil {
		ProgramPanic(err)
	}
	//fmt.Printf("Sender have done sending the campaign: %s\n", uid)
	//Logas("Sender have done sending the campaign: %s\n", uid)
}

// DetectTrackingDomain - This function basically checks, what tracking domain we should use
func DetectTrackingDomain(campaign Campaign, subscriber Subscriber) string {
	var trackurl string
	if campaign.Trackurl == "" {
		trackurl = subscriber.Track_url
	} else {
		trackurl = campaign.Trackurl
	}
       Logas("Tracking domain for campaign was set to: %s\n",campaign.Trackurl)
	// FIXME here we check if the domain string is not empty and if it's empty we can pull the global tracking domain into action
	return trackurl
}

// GetRandomSubscriber - Gets random subscriber from the campaign queue list sitting on redis backend
func GetRandomSubscriber(uid string, tip bool, wg *sync.WaitGroup) (Subscriber, error) {
	t0 := time.Now()
	var sub Subscriber
	var condition string
	if tip == true {
		condition = "campaign_" + uid + "_subscribers_test"
	} else {
		condition = "campaign_" + uid + "_subscribers"
	}
	tries_count := 0
	for {
		val, err := redisdb.SPop(condition).Result()
		if err != nil {
			tries_count++
			fmt.Printf("Unable to retrieve more subscribers, error: %s\n", err)
			fmt.Printf("We will retry this method now, as it seems to be the false positive error...\n")
			Logas("Unable to retrieve subscriber data from redis: %s will retry...\n", err)
			if tries_count > 30 && nodone == false && test == false {
				tries_count = 0
				ChangeCampaignStatus(uid, "done")
				fmt.Printf("We have considered that the campaign %s does not have anymore subscribers in the redis backend list\n", uid)
				Logas("We have considered that the campaign %s does not have anymore subscribers in the redis backend list\n", uid)
				ThreadWaitTimeout(wg)
				//os.Exit(0)
			}
			// got nil value, the list is 100% empty
			if err == redis.Nil {
				if nodone == false && test == false {
					ChangeCampaignStatus(uid, "done")
				}
				// another issue that the tracking inserting maybe is done, and there are lacks of subscribers somehow, we will check if tracking is done and then finish the campaign
				if IfTrackingDone(uid) == true && test == false {
					fmt.Printf("We have identified that the tracking log have been completely inserted both in mysql and redis backend, and we have processsed all the record for that task, just marking this campaign as finished!\n")
					Logas("We have identified that the tracking log have been completely inserted both in mysql and redis backend, and we have processsed all the record for that task, just marking this campaign as finished!\n")
					ChangeCampaignStatus(uid, "done")
				}

				// if the test is running and the test server is too slow we need to make some sleep, later we will implement wg.GroupWait for ThreadWaiting
				// maybe there is a need to sleep for other reasons too, because the last send email can't be non delivered on the slow server
				if test == true {
					fmt.Printf("Test environment is running, test was passed and we will do some sleep here to ensure that the delivery was ok\n")
					Logas("Test environment is running, test was passed and we will do some sleep here to ensure that the delivery was ok\n")
					time.Sleep(5 * time.Second) // need to adjust this frequently, maybe add it to the settings or smth
				}
				fmt.Printf("We got nil data from the redis database backend for campaign %s, exiting...\n", uid)
				Logas("We got nil data from the redis database backend for campaign %s, exiting...\n", uid)
				//Logas("I've reached the unreachable code :D...\n")
				ThreadWaitTimeout(wg)
				//time.Sleep(7 * time.Second)
				//os.Exit(0)
			}
			// we should make some sleep here for a second or more
			time.Sleep(1 * time.Second)
			continue

		}

		if err == nil {
			err = json.Unmarshal([]byte(val), &sub)
			if err != nil {
				fmt.Printf("FIXME: subscriber json parse error uid: %s\n", uid)
				Logas("FIXME: subscriber json parse error uid: %s\n", uid)
				//return sub, err
				continue
			}
			t2 := time.Now()
			if BENCHMARK > 0 {
				fmt.Printf("Get randomsubscriber took: %s\n", t2.Sub(t0))
			}
			return sub, nil
		} else {
			continue
		}
	}
	return sub, nil
}

func RedisInit() {
	redisdb = redis.NewClient(&redis.Options{Addr: "localhost:6379", Password: "", DB: 0})
}

func SendGridInit() {

}

func SESInit() {
	fmt.Printf("Starting to initialize the SES support...\n")
	creds := credentials.NewStaticCredentials(accesskey, secretkey, "")
	// Create a new session and specify an AWS Region.
	sess, err := session.NewSession(&aws.Config{
		Region: aws.String(region), Credentials: creds},
	)
	svc = ses.New(sess)

	if err != nil {
		if aerr, ok := err.(awserr.Error); ok {
			switch aerr.Code() {
			case ses.ErrCodeMessageRejected:
				Logas(ses.ErrCodeMessageRejected, aerr.Error())
				fmt.Println(ses.ErrCodeMessageRejected, aerr.Error())
			case ses.ErrCodeMailFromDomainNotVerifiedException:
				Logas(ses.ErrCodeMailFromDomainNotVerifiedException, aerr.Error())
				fmt.Println(ses.ErrCodeMailFromDomainNotVerifiedException, aerr.Error())
			case ses.ErrCodeConfigurationSetDoesNotExistException:
				Logas(ses.ErrCodeConfigurationSetDoesNotExistException, aerr.Error())
				fmt.Println(ses.ErrCodeConfigurationSetDoesNotExistException, aerr.Error())
			default:
				Logas(aerr.Error())
				fmt.Println("Got amazon initialization error: %s\n", aerr.Error())
			}
		} else {
			// Print the error, cast err to awserr.Error to get the Code and
			// Message from an error.
			Logas(err.Error())
			fmt.Println("Got amazon ses error: %s", err.Error())
		}
		fmt.Printf("That was the fatal error, cannot continue!\n")
		Logas("That was the fatal error, cannot continue!\n")
		//ThreadWaitTimeout()
		os.Exit(1)
		return
	}
	fmt.Printf("Amazon ses successfully initialized!\n")
	return
	// TODO we need to implement self checking here, to check if account is dead or not, and report back to campaign as error message
}

func ProgramPanic(err error) {
	Logas("Program panic: %s\n", err)
	panic(err)
}

func getCampaign(uid string) (Campaign, error) {
	t0 := time.Now()
	val, err := redisdb.Get(uid).Result()
	if err != nil {
		panic(err)
	}
	var camp Campaign
	err = json.Unmarshal([]byte(val), &camp)
	if err != nil {
		fmt.Printf("Unable to parse redis campaign %s json. There was an error: %s", uid, err)
		Logas("Unable to parse redis campaign %s json. There was an error: %s", uid, err)
	}
	t2 := time.Now()
	if BENCHMARK > 0 {
		fmt.Printf("GetCampaign took: %s\n", t2.Sub(t0))
	}
	return camp, err
}

func ReturnUrlPart(typeas string) string {
	t0 := time.Now()
	part, err := redisdb.SRandMember(typeas + "part").Result()
	if err != nil {
		return typeas
	}
	t2 := time.Now()
	if BENCHMARK > 0 {
		fmt.Printf("ReturnURlPart took: %s\n", t2.Sub(t0))
	}
	return part
}

func IncreaseCounter(uid string) {
	t0 := time.Now()
	if test == false {
		redisdb.Incr(uid + "_counter").Err()
	}
	t2 := time.Now()
	if BENCHMARK > 0 {
		fmt.Printf("IncreaseCounter took: %s\n", t2.Sub(t0))
	}
	return
}

// GenerateRandomBytes returns securely generated random bytes.
// It will return an error if the system's secure random
// number generator fails to function correctly, in which
// case the caller should not continue.
func GenerateRandomBytes(n int) ([]byte, error) {
	t0 := time.Now()
	b := make([]byte, n)
	_, err := rand.Read(b)
	// Note that err == nil only if we read len(b) bytes.
	if err != nil {
		return nil, err
	}
	t2 := time.Now()
	if BENCHMARK > 0 {
		fmt.Printf("GenerateRandomBytes took: %s\n", t2.Sub(t0))
	}
	return b, nil
}

func randomDateInteger() string {
	now := time.Now()
	nanos := now.UnixNano()
	nanoso := nanos / 10000
	millis := fmt.Sprintf("%d", nanoso)
	for i := 1; i < 3; i++ {
		millis = millis[1:]
	}
	first := RandomInteger(10, 10000)
	rez := fmt.Sprintf("%d%s", first, millis)
	return rez
}

func RandomInteger(min, max int) int {
	rand.NewSource(time.Now().UnixNano())
	return rand.Intn(max-min) + min
}

func RandomStuff(len int) string {
	t0 := time.Now()
	b, _ := GenerateRandomBytes(len)
	t2 := time.Now()
	if BENCHMARK > 0 {
		fmt.Printf("RandomStuff took: %s\n", t2.Sub(t0))
	}
	return base64.URLEncoding.EncodeToString(b)
}

// EncodeTrackUrl - Commonly used to inject sending server ip to the tracking url in email
func EncodeTrackUrl(trackurl, message_id string) string {
	t0 := time.Now()
	message_id = strings.Replace(message_id, "{SRVBL}", smtphost, -1)
	encoded_message_id := base64.StdEncoding.EncodeToString([]byte(message_id))
	TextReplacer := strings.NewReplacer(
		"+", "-",
		"/", "_",
		"=", ",",
	)
	encoded_message_id = TextReplacer.Replace(encoded_message_id)
	trackurl = strings.Replace(trackurl, "MESSAGE_ID", encoded_message_id, -1)
	t2 := time.Now()
	if BENCHMARK > 0 {
		fmt.Printf("EncodeTrackUrl took: %s", t2.Sub(t0))
	}
	return trackurl
}

func SpecificEncodeForClickLink(url string) string {
	encoded_click_url := base64.StdEncoding.EncodeToString([]byte(url))
	TextReplacer := strings.NewReplacer(
		"+", "-",
		"/", "_",
	)
	encoded_click_url = TextReplacer.Replace(encoded_click_url)
	sz := len(encoded_click_url)

	if sz > 0 && encoded_click_url[sz-1] == '=' {
		encoded_click_url = encoded_click_url[:sz-1]
	}

	return encoded_click_url
}

func GenCampaignRandomIntegers() string {
	return fmt.Sprintf("%dd2g8t0%d", RandomInteger(10, 999), RandomInteger(99, 300000))
}

func EncodeClickUrl(exacturl, domain string, subscriber Subscriber, campuid string) string {
	url := "http://{DOMAIN}/{CAMPAIGN}/MESSAGE_ID/{CLICKPART}/URL"
	// replace exact url by injecting the uid of the subscriber
	TextReplacer := strings.NewReplacer(
		"{SUBID1}", subscriber.Uid,
		"{SUBID2}", campuid,
	)
	exacturl = TextReplacer.Replace(exacturl)

	TextReplacer = strings.NewReplacer(
		"{DOMAIN}", domain,
		"{CAMPAIGN}", GenCampaignRandomIntegers(),
		"MESSAGE_ID", subscriber.Msgid2,
		"{CLICKPART}", ReturnUrlPart("click"),
		"URL", SpecificEncodeForClickLink(exacturl))
	url = TextReplacer.Replace(url)
	return url
}

type TrackingLink struct {
	Campaign_uid  string `json:"campaign_uid"`
	Link_type     int    `json:"link_type"`     // { open => 0, link => 1, unsubscribe => 2, image => 3 }
	Redirect_id   int    `json:"redirect_id"`   // link id in campaigns
	Redirect_type int    `json:"redirect_type"` // { 301 => 1, js => 2 }
	Test          int    `json:"test"`          // 0 , 1 (Testas)
	Email         string `json:"email"`
	Message_id    string `json:"message_id"`
	Subscriber_id int    `json:"subscriber_id"`
	Server        string `json:"server"`
}

// Function that create remote tracking log on the remote storage
func PostTrackingv3(Tracking TrackingLink) string {
        storurl := settings.StorageUrl
        storkey := settings.StorageKey
        if settings.EnableLinker {
        storurl =  settings.LinkerUrl
        storkey =  settings.LinkerKey
        }
	timeout := settings.StorageTimeout // maximum of 10 secs
	jsonas, err := json.Marshal(Tracking)
	if err != nil {
		fmt.Printf("Unable to marshall the json for remote storage tracking log: %s\n", err)
		os.Exit(1)
	}
	ApiClient := http.Client{
		Timeout: time.Second * time.Duration(timeout),
	}
	req, err := http.NewRequest(http.MethodPost, storurl+"api/v1/links/postlink", bytes.NewBuffer(jsonas))
	if err != nil {
		fmt.Printf("PostTrackingv3 err1: %s\n", err)
		os.Exit(1)
	}
	req.Header.Set("Authorization", storkey)
	res, getErr := ApiClient.Do(req)
	if getErr != nil {
		// TODO implement here a reporting to campaign->error
		fmt.Printf("PostTrackingv3 Critical error, we are unable to reach storage api http service: %v\n", err)
		//ThreadWaitTimeout()
		os.Exit(1)
	}
	if DEBUG > 0 {
		fmt.Printf("I've got respond for PostTrackingv3: %s\n", res.Status)
	}
	if res.StatusCode > 0 && res.StatusCode < 404 {
		if res.StatusCode == 200 {
			buf := new(bytes.Buffer)
			buf.ReadFrom(res.Body)
			var returnval string
			err = json.Unmarshal([]byte(buf.String()), &returnval)
			if err != nil {
				return ""
			}
			return returnval
		}
	}
	return ""
}

func GetImagev3(uid string, number string) string {
	stat, err := redisdb.HExists("campaign_"+uid+"_images", number).Result()
	if err == nil && stat {
		img, err := redisdb.HGet("campaign_"+uid+"_images", number).Result()
		if err == nil {
			return img
		}
	}
	return ""
}

func GenerateCampaignTemplate(uid, html string, subscriber Subscriber, trackdomain string, trackingtype string) string {
	t0 := time.Now()
        // for https implementation 2022.09.08
        http_url := "http://"
        if HTTPS_LINKS {
            http_url = "https://"
        }

	if trackingtype == "0" {
		// Replace functions
		//	html = strings.Replace(html, "{domain}", domain, -1)
		modified_tracking := EncodeTrackUrl(subscriber.Tracking_url, subscriber.Open_tracking)
		// Prepare open tracking log url
		inject_open_track_url := "<img src=\"" + modified_tracking + "\" width=\"0\" height=\"0\" alt=\"\" style=\"visibility:hidden\" /><!-- " + subscriber.Uid + " -->"
		TextReplacer := strings.NewReplacer("{DOMAIN}", trackdomain, "{OPENPART}", ReturnUrlPart("open"))
		inject_open_track_url = TextReplacer.Replace(inject_open_track_url)
		// prepare global tracking log
		subscriber.Tracking_url = strings.Replace(subscriber.Tracking_url, "{DOMAIN}", trackdomain, -1)
		// prepare profile update url
		TextReplacer = strings.NewReplacer("{DOMAIN}", trackdomain, "{PROFILEPART}", ReturnUrlPart("profile"))
		subscriber.Update_url = TextReplacer.Replace(subscriber.Update_url)
		// prepare unsubsribe url
		TextReplacer = strings.NewReplacer("{DOMAIN}", trackdomain, "{UNSUBSCRIBEPART}", ReturnUrlPart("unsubscribe"))
		subscriber.Unsubscribe_url = TextReplacer.Replace(subscriber.Unsubscribe_url)
		// replace the main HTML template
		TextReplacer = strings.NewReplacer(
			"{SOURCEPART}", ReturnUrlPart("source"),
			"{CLICKPART}", ReturnUrlPart("click"),
			"{OPENPART}", ReturnUrlPart("open"),
			"{DOMAIN}", trackdomain,
			"{domain}", trackdomain,
			"MESSAGE_ID", subscriber.Msgid1,
			"MSGID2", subscriber.Msgid2,
			"{UPDATE_PROFILE_URL}", subscriber.Update_url,
			"{UNSUBSCRIBE_URL}", subscriber.Unsubscribe_url,
			"{REPORT_URL}", http_url+trackdomain+"/"+REPORT_PREFIX,
			"{CONTACT_NAME}", "",
			"{CONTACT_EMAIL}", "",
			"{EMAIL}", subscriber.Email,
			"{RANDOM}", RandomStuff(RandomInteger(50, 550)),
			"{UID}", subscriber.Uid,
			"amp;", "",
			"</content>", inject_open_track_url+"</content>",
		)

		// here we need to replace all domains in url links with trackdomain
		// $html =~ s/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,7}(\:[0-9]+)?\/?/http:\/\/$trackurl\//g;
		// FIXME this section should be improved by merging all regex variants to one query and process them altogether with other textreplacer objects
		// VERY SLOWLY processing
		// FIXME known bug, that is detecting bol.com domain as the hypertext link, but it's not
		// New implementation

// This one is from new tracking type

                re := regexp.MustCompile(`(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,7}(\:[0-9]+)?\/?`)
                rx := xurls.Relaxed()
                urls := rx.FindAllString(html, -1)
                for _, url := range urls {
                        if DEBUG > 0 {
                                Logas("Found url1: %s", url)
                        }
                        if strings.Contains(url, "{SUBID1}") || strings.Contains(url, "{SUBID2}") || strings.Contains(url, "{SUBID5}") {
                                if DEBUG > 0 {
                                        Logas("We detected the link %s for subscriber %s with {SUBID1} or {SUBID2} or {SUBID5}", url, subscriber.Email)
                                }
                                        TextReplacer = strings.NewReplacer(url, EncodeClickUrl(url, trackdomain, subscriber, uid))
                                        html = TextReplacer.Replace(html)
                                } else {
                                        // urls without id's some images/css/other shit
                                        submatchall := re.FindString(url)
                                        // FIXME should be removed
                                        if submatchall != "" && strings.Contains(submatchall, "http") {
                                                if DEBUG > 0 {
                                                        Logas("Found alone url %s with domain %s", url, submatchall)
                                                }
                                                TextReplacer := strings.NewReplacer(submatchall, http_url+trackdomain+"/")
                                                url_replaced := TextReplacer.Replace(url)
                                                if DEBUG > 0 {
                                                        Logas("Replaced url is: %s", url_replaced)
                                                }
                                                // replace html with this new url
                                                html = strings.Replace(html, url, url_replaced, -1)
                                       }
                        }
                }
                // do some tags rewrite
                html = TextReplacer.Replace(html)
                // custom hmtl tags
                if settings.RandomHtmlTags {
                html = HtmlRandomTagsProcess(html)
                }
                if subscriber.CustomFieldsEnabled {
                html = GenCustomFields(html,subscriber)
                }

                // redis set tracking log

/*		re := regexp.MustCompile(`(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,7}(\:[0-9]+)?\/?`)
		rx := xurls.Relaxed()
		urls := rx.FindAllString(html, -1)
		for _, url := range urls {
			Logas("Found url: %s", url)
			if strings.Contains(url, "{SUBID1}") || strings.Contains(url, "{SUBID2}") {
				Logas("We detected the link %s for subscriber %s with {SUBID1} or {SUBID2}", url, subscriber.Email)
				TextReplacer = strings.NewReplacer(url, EncodeClickUrl(url, trackdomain, subscriber, uid))
				html = TextReplacer.Replace(html)
			} else {
				submatchall := re.FindString(url)
				//if submatchall != "" && strings.Contains(submatchall, "http") {
				Logas("Found standard url %s with domain %s", url, submatchall)
				TextReplacer = strings.NewReplacer(submatchall, "http://"+trackdomain+"/")
				url_replaced := TextReplacer.Replace(url)
				Logas("Replaced url is: %s", url_replaced)
				// replace html with this new url
				html = strings.Replace(html, url, url_replaced, -1)
				//}
			}
		}
*/

		// old implementation
		// re := regexp.MustCompile(`(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,7}(\:[0-9]+)?\/?`)
		// submatchall := re.FindAllString(html, -1)
		// for _, element := range submatchall {
		// 	Logas("We detected the link %s for subscriber %s", element, subscriber.Email)
		// 	// check if {SUBID1} exists in the string, if yes then encode it on it's own
		// 	// else do all the stuff in the standard way as before
		// 	if strings.Contains(element, "{SUBID1}") {
		// 		Logas("We detected the link %s for subscriber %s with {SUBID1}", element, subscriber.Email)
		// 		TextReplacer = strings.NewReplacer(element, EncodeClickUrl(element, trackdomain, subscriber))
		// 		html = TextReplacer.Replace(html)
		// 	} else {
		// 		TextReplacer = strings.NewReplacer(element, "http://"+trackdomain+"/")
		// 		html = TextReplacer.Replace(html)
		// 	}
		// }
	} else if trackingtype == "1" {
		// New type tracking
		tracking := TrackingLogItem{}
		tracking.Campuid = campaign
		tracking.Trackid = subscriber.Message_id
		tracking.Test = test
		tracking.Opened = false
		tracking.Clicked = false
		if smtphost != "" {
			tracking.Server = smtphost
		} else if accesskey != "" {
			tracking.Server = "amazon api"
		}
		// NEW IMPLEMENTATION
		var identification string = randomDateInteger()
		for {
			val, err := redisdb.HExists("tracking_logs", identification).Result()
			if err == nil && val != true {
				break
			}
			identification = randomDateInteger()
			if DEBUG > 0 {
				fmt.Printf("Got new identification: %s\n", identification)
			}
		}

		// OLD IMPLEMENTATION
		/*		var identification string = fmt.Sprintf("%d", subscriber.Id)
				if test == true {
					identification = "1"
				}

				for {
					val, err := redisdb.HExists("tracking_logs", identification).Result()
					if err == nil && val != true {
						break
					}
					identification = fmt.Sprintf("%d", RandomInteger(100000, 99000000000000000))
					if DEBUG > 0 {
						fmt.Printf("Got new identification: %s\n", identification)
					}
				}
		*/
		Logas("Got tracking identification number: %s", identification)
		modified_tracking := fmt.Sprintf("%s%s/%s.gif", http_url,trackdomain, HashIt(fmt.Sprintf("%v", identification)))
		inject_open_track_url := "<img src=\"" + modified_tracking + "\" width=\"0\" height=\"0\" alt=\"\" style=\"visibility:hidden\" /><!-- " + subscriber.Uid + " -->"
		unsubscribe_url := fmt.Sprintf("%s%s/%s", http_url,trackdomain, HashIt(fmt.Sprintf("%v", identification)))
		// replace the main HTML template
		TextReplacer_tags := strings.NewReplacer(
			//"{SOURCEPART}", ReturnUrlPart("source"),
			//	"{CLICKPART}", ReturnUrlPart("click"),
			//"{OPENPART}", ReturnUrlPart("open"),
			"{DOMAIN}", trackdomain,
			"{domain}", trackdomain,
			//"MESSAGE_ID", subscriber.Msgid1,
			//"MSGID2", subscriber.Msgid2,
			//"{UPDATE_PROFILE_URL}", subscriber.Update_url,
			"{UNSUBSCRIBE_URL}", unsubscribe_url,
			"{REPORT_URL}", http_url+trackdomain+"/"+REPORT_PREFIX,
			"{CONTACT_NAME}", "",
			"{CONTACT_EMAIL}", "",
			"{EMAIL}", subscriber.Email,
			"{RANDOM}", RandomStuff(RandomInteger(50, 550)),
			"{UID}", subscriber.Uid,
			"amp;", "",
			"</content>", inject_open_track_url+"</content>",
		)
		//html = TextReplacer_tags.Replace(html)

		// here we need to replace all domains in url links with trackdomain
		// $html =~ s/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,7}(\:[0-9]+)?\/?/http:\/\/$trackurl\//g;
		// FIXME this section should be improved by merging all regex variants to one query and process them altogether with other textreplacer objects
		// VERY SLOWLY processing
		// New implementation
		re := regexp.MustCompile(`(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,7}(\:[0-9]+)?\/?`)
		rx := xurls.Relaxed()
		urls := rx.FindAllString(html, -1)
		for _, url := range urls {
			if DEBUG > 0 {
				Logas("Found url1: %s", url)
			}
			if strings.Contains(url, "{SUBID1}") || strings.Contains(url, "{SUBID2}") || strings.Contains(url, "{SUBID5}") {
				if DEBUG > 0 {
					Logas("We detected the link %s for subscriber %s with {SUBID1} or {SUBID2} or {SUBID5}", url, subscriber.Email)
				}
				provider := GetProvider(subscriber.Email)
				// we had to put suburl to the redis tracking_logs
				urlid := GetUrlId(url)
				if urlid > 0 {
					SuburlReplacer := strings.NewReplacer(
						"{SUBID1}", subscriber.Uid,
						"{SUBID2}", campaign,
						"{SUBID5}", provider,
					)
					tracking.Suburl = SuburlReplacer.Replace(url)
					urlid := GetUrlId(url)
					urlreplacement := fmt.Sprintf("%s%s/", http_url,trackdomain) + HashIt(fmt.Sprintf("%vu%d", identification, urlid))
					// then we change the url to point to the correct url
					TextReplacer := strings.NewReplacer(url, urlreplacement)
					html = TextReplacer.Replace(html)
				}
			} else {
				urlid := GetUrlId(url)
				if DEBUG > 0 {
					Logas("Getting id for url: %s id: %s", url, urlid)
				}
				if urlid > 0 {
					submatchall := re.FindString(url)
					if submatchall != "" && strings.Contains(submatchall, "http") {
						//if submatchall != "" {
						if DEBUG > 0 {
							Logas("Found tracking url %s with domain %s", url, submatchall)
						}
						urlreplacement := fmt.Sprintf("%s%s/", http_url,trackdomain) + HashIt(fmt.Sprintf("%vu%d", identification, urlid))
						TextReplacer := strings.NewReplacer(url, urlreplacement)
						url_replaced := TextReplacer.Replace(url)
						// replace html with this new url
						html = strings.Replace(html, url, url_replaced, -1)
					}
				} else {
					// urls without id's some images/css/other shit
					submatchall := re.FindString(url)
					// FIXME should be removed
					if submatchall != "" && strings.Contains(submatchall, "http") {
						if DEBUG > 0 {
							Logas("Found alone url %s with domain %s", url, submatchall)
						}
						TextReplacer := strings.NewReplacer(submatchall, http_url+trackdomain+"/")
						url_replaced := TextReplacer.Replace(url)
						if DEBUG > 0 {
							Logas("Replaced url is: %s", url_replaced)
						}
						// replace html with this new url
						html = strings.Replace(html, url, url_replaced, -1)
					}
				}
			}
		}
		// do some tags rewrite
		html = TextReplacer_tags.Replace(html)
                // html random tags
                if settings.RandomHtmlTags {
                html = HtmlRandomTagsProcess(html)
                }
                if subscriber.CustomFieldsEnabled {
                html = GenCustomFields(html,subscriber)
                }
		// redis set tracking log
		jsonas, err := json.Marshal(tracking)
		if err == nil {                 
		        if CAMPAIGN_LOGGING {
                        	WriteCampaignLog(string(identification))
                        }
			redisdb.HSet("tracking_logs", string(identification), jsonas).Err()
			if err != nil {
				Logas("Unable to store the tracking_log information on redis for subscriber: %s in campaign %s with tracking_log %s", subscriber.Uid, campaign, tracking)
				// ProgramPanic(err)
			}
		} else {
			Logas("Error in marshaling the json data for tracking_log, subscriber: %s campaign: %s trackdata: %s", subscriber.Uid, campaign, tracking)
		}
	} else if trackingtype == "3" {
		Logas("Warning this section is not implemented yet and it's in the alpha stage")
		TrackingBase := TrackingLink{Campaign_uid: campaign, Redirect_type: 1, Email: subscriber.Email, Message_id: subscriber.Message_id, Subscriber_id: subscriber.Id}
		//TrackingBase.Link_type = 1 // { open => 0, link => 1, unsubscribe => 2, image => 3 }
		//TrackingBase.Redirect_id = 1 // link id in campaigns
		TrackingBase.Test = 0
		if test {
			TrackingBase.Test = 1
		}
		if smtphost != "" {
			TrackingBase.Server = smtphost
		} else if accesskey != "" {
			TrackingBase.Server = "amazon api"
		}
		// walk trough all html and count links
		regexas := regexp.MustCompile(`<[-_=a-z0-9" ]+(?:href)+="([^"]+)"`)
		matches := regexas.FindAllStringSubmatch(html, -1)
		urlcount := 0
		for _, tmpurl := range matches {
			url := tmpurl[1]
			if strings.Contains(url, "{UNSUBSCRIBE_URL}") || strings.Contains(url, "{REPORT_URL}") || strings.Contains(url, "{EMAIL}") || strings.Contains(url, "{RANDOM}") {
				Logas("Found {TAG} on html: %s\n", url)
				if strings.Contains(url, "{UNSUBSCRIBE_URL}") {
					NaujasUrl := TrackingBase
					NaujasUrl.Link_type = 2
					NaujasUrl.Redirect_id = -1
					RemoteTrackID := PostTrackingv3(NaujasUrl)
					urlreplacement := http_url + trackdomain + "/" + RemoteTrackID
					html = strings.Replace(html, url, urlreplacement, -1)
				}
				if strings.Contains(url, "{REPORT_URL}") {
					urlreplacement := http_url + trackdomain + "/"+ REPORT_PREFIX
					html = strings.Replace(html, url, urlreplacement, -1)
				}
				if strings.Contains(url, "{EMAIL}") {
					tagreplacement := subscriber.Email
					html = strings.Replace(html, url, tagreplacement, -1)
				}
				if strings.Contains(url, "{RANDOM}") {
					tagreplacement := RandomStuff(RandomInteger(50, 550))
					html = strings.Replace(html, url, tagreplacement, -1)
				}
			} else {
				Logas("Url is nice: %s\n", url)
				NaujasUrl := TrackingBase
				NaujasUrl.Link_type = 1
				NaujasUrl.Redirect_id = urlcount
				RemoteTrackID := PostTrackingv3(NaujasUrl)
				urlreplacement := http_url + trackdomain + "/" + RemoteTrackID
				html = strings.Replace(html, url, urlreplacement, -1)
				if DEBUG > 0 {
					Logas("We got url: %s -> replacement: %s\n", url, urlreplacement)
				}
				urlcount++
			} // end else
		} // end for url
		// we should process images also
		regexas2 := regexp.MustCompile(`<[-_=a-z0-9" ]+(?:src)+="([^"]+)"`)
		matches2 := regexas2.FindAllStringSubmatch(html, -1)
		imgcount := 0
		for _, tmpimg := range matches2 {
			img := tmpimg[1]
			Logas("Found image: %s\n", img)
			imagefile := GetImagev3(uid, fmt.Sprintf("%d", imgcount))
			if imagefile == "" {
				imagefile = "nofile.png"
			}
			imagereplacement := http_url + trackdomain + "/" + imagefile
			html = strings.Replace(html, img, imagereplacement, -1)
			Logas("We got image: %s -> replacement: %s\n", img, imagereplacement)
			imgcount++
		}
		// here we should do regexp on the rest of all urls to finally change the domain to the tracking domain

		// here we should implement the open tracking
		NaujasUrl := TrackingBase
		NaujasUrl.Link_type = 0
		NaujasUrl.Redirect_id = -1
		RemoteTrackID := PostTrackingv3(NaujasUrl)
		modified_tracking := http_url + trackdomain + "/" + RemoteTrackID
		inject_open_track_url := "<img src=\"" + modified_tracking + "\" width=\"0\" height=\"0\" alt=\"\" style=\"visibility:hidden\" /><!-- " + subscriber.Uid + " -->"
		html = strings.Replace(html, "</content>", inject_open_track_url+"</content>", -1)
                // html custom tags
                if settings.RandomHtmlTags {
                html = HtmlRandomTagsProcess(html)
                }
                if subscriber.CustomFieldsEnabled {
                html = GenCustomFields(html,subscriber)
                }
		Logas("HTML AFTER: %s\n", html) // FIXME REMOVE

		Logas("We leave the uninmplemented section\n")

	} else {
		// No tracking
		tracking := TrackingLogItem{}
		tracking.Campuid = campaign
		tracking.Trackid = subscriber.Message_id
		tracking.Test = test
		tracking.Opened = false
		tracking.Clicked = false
		if smtphost != "" {
			tracking.Server = smtphost
		} else if accesskey != "" {
			tracking.Server = "amazon api"
		}
		// NEW
		var identification string = randomDateInteger()
		for {
			val, err := redisdb.HExists("tracking_logs", identification).Result()
			if err == nil && val != true {
				break
			}
			identification = randomDateInteger()
			if DEBUG > 0 {
				fmt.Printf("Got new identification: %s\n", identification)
			}
		}

		// OLD
		/*		var identification string = fmt.Sprintf("%d", subscriber.Id)
				if test == true {
					identification = "1"
				}

				for {
					val, err := redisdb.HExists("tracking_logs", identification).Result()
					if err == nil && val != true {
						break
					}
					identification = fmt.Sprintf("%d", RandomInteger(10, 9900000000))
				}
		*/
		Logas("Got tracking identification number: %s", identification)
		modified_tracking := fmt.Sprintf("%s%s/%s.gif", http_url,trackdomain, HashIt(fmt.Sprintf("%v", identification)))
		inject_open_track_url := "<img src=\"" + modified_tracking + "\" width=\"0\" height=\"0\" alt=\"\" style=\"visibility:hidden\" /><!-- " + subscriber.Uid + " -->"
		unsubscribe_url := fmt.Sprintf("%s%s/%s", http_url,trackdomain, HashIt(fmt.Sprintf("%v", identification)))
		// replace the main HTML template
		TextReplacer := strings.NewReplacer(
			"{DOMAIN}", trackdomain,
			"{domain}", trackdomain,
			"{UNSUBSCRIBE_URL}", unsubscribe_url,
			"{REPORT_URL}", http_url+trackdomain+"/"+REPORT_PREFIX,
			"{CONTACT_NAME}", "",
			"{CONTACT_EMAIL}", "",
			"{EMAIL}", subscriber.Email,
			"{RANDOM}", RandomStuff(RandomInteger(50, 550)),
			"{UID}", subscriber.Uid,
			"amp;", "",
			"</content>", inject_open_track_url+"</content>",
		)
		html = TextReplacer.Replace(html)
		var myRegex = regexp.MustCompile(`<img[^>]+\bsrc=["']([^"']+)["']`)
		var imgTags = myRegex.FindAllStringSubmatch(html, -1)
		out := make([]string, len(imgTags))
		for i := range out {
			fmt.Println(imgTags[i][1])
		}

		// no tracking end
	}
	t2 := time.Now()
	if DEBUG > 0 {
		fmt.Printf("Campaign: %s HTML Generation for subscriber: %s with domain: %s took: %s\n", color.FgCyan.Render(uid), color.FgCyan.Render(subscriber.Email), color.FgYellow.Render(trackdomain), t2.Sub(t0))
	}
	Logas("Campaign: %s HTML Generation for subscriber: %s with domain: %s took: %s\n", uid, subscriber.Email, trackdomain, t2.Sub(t0))
	return html
}

func GetUrlId(url string) int64 {
	Data, err := redisdb.HGetAll("campaigns_links").Result()
	if err != nil {
		return 0
	}
	for id, val := range Data {
		if val == url {
			if DEBUG > 0 {
				Logas("URL: %s Val is equal to: %s\n", url, id)
			}
			Idas, err := strconv.ParseInt(id, 0, 64)
			if err != nil {
				return 0
			}
			return Idas
		}
	}
	return 0
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

func HashIt(text string) string {
	//algorithm := map[string]string{"1":"s", "2":"m", "3":"z", "4":"c", "5":"k", "6":"e", "7":"r", "8":"l", "9":"o", "0":"p", "u":"a"}
	if DEBUG > 0 {
		Logas("Hashing text: %s", text)
	}
	algorithm := settings.Algorithm
	output := ""
	for _, char := range text {
		for echar := range algorithm {
			if echar == string(char) {
				output = output + algorithm[echar]
				break
			}
		}
	}
	if DEBUG > 0 {
		Logas("Hashed text: %s", output)
		Logas("Unhashed text: %s", UnhashIt(output))
	}
	return output
}

func UnhashIt(encoded string) string {
	//algorithm := map[string]string{"1":"s", "2":"m", "3":"z", "4":"c", "5":"k", "6":"e", "7":"r", "8":"l", "9":"o", "0":"p", "u":"a"}
	algorithm := settings.Algorithm
	output := ""
	for _, char := range encoded {
		for echar := range algorithm {
			if algorithm[echar] == string(char) {
				output = output + echar
				break
			}
		}
	}
	return output
}

type SmtpServer struct {
	Host      string
	Port      string
	TlsConfig *tls.Config
}

func (s *SmtpServer) ServerName() string {
	return s.Host + ":" + s.Port
}

// generates random symbol using min max values
func RandSymCount(min, max int) string {
length := rand.Intn(max-min) + min
var letter = []rune("±!@#$%^&*()_+=-[]{};'\\:\"|,./<>?`~")

        b := make([]rune, length)
        for i := range b {
                b[i] = letter[rand.Intn(len(letter))]
        }
        return string(b)
}

// generates random string using min max values
func RandStrCount(min, max int) string {
length := rand.Intn(max-min) + min
var letter = []rune("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789")

        b := make([]rune, length)
        for i := range b {
                b[i] = letter[rand.Intn(len(letter))]
        }
        return string(b)
}

func GetDate() string {
dt := time.Now()
return fmt.Sprintf("%s",dt.Format("Mon, 02 Jan 2006 15:04:05 -0700"))
}

func PropagateHeaderVal(val string, email string) string {
/// random number generation
rndnumregex := regexp.MustCompile(`{rndnum\[(\d+)\,(\d+)]}`)
rndnum := rndnumregex.Match([]byte(val))
if rndnum {
	repl_string := rndnumregex.FindAllString(val,-1)
	for accour, str := range repl_string {
		length,_:= strconv.Atoi(rndnumregex.FindStringSubmatch(str)[1])
	        length2,_ := strconv.Atoi(rndnumregex.FindStringSubmatch(str)[2])
		if DEBUG > 0 {
			fmt.Printf("Find target replacement int: %s length: %d length2: %d accourance: %d\n",str,length,length2,accour)
        	}
		val = strings.Replace(val, str, fmt.Sprintf("%d",RandomInteger(length,length2)), -1)
        }
}
/// random string generation
rndstrregex := regexp.MustCompile(`{rndstr\[(\d+)\,(\d+)]}`)
rndstr := rndstrregex.Match([]byte(val))
if rndstr {
        repl_string := rndstrregex.FindAllString(val,-1)
        for accour, str := range repl_string {
                length,_:= strconv.Atoi(rndstrregex.FindStringSubmatch(str)[1])
                length2,_ := strconv.Atoi(rndstrregex.FindStringSubmatch(str)[2])
                if DEBUG > 0 {
                        fmt.Printf("Find target replacement string: %s length: %d length2: %d accourance: %d\n",str,length,length2,accour)
                }
                val = strings.Replace(val, str, string(RandStrCount(length,length2)), -1)
        }
}
/// random symbol generation
rndsymregex := regexp.MustCompile(`{rndsym\[(\d+)\,(\d+)]}`)
rndsym := rndsymregex.Match([]byte(val))
if rndsym {
        repl_string := rndsymregex.FindAllString(val,-1)
        for accour, str := range repl_string {
                length,_:= strconv.Atoi(rndsymregex.FindStringSubmatch(str)[1])
                length2,_ := strconv.Atoi(rndsymregex.FindStringSubmatch(str)[2])
                if DEBUG > 0 {
                        fmt.Printf("Find target replacement string: %s length: %d length2: %d accourance: %d\n",str,length,length2,accour)
                }
                val = strings.Replace(val, str, string(RandSymCount(length,length2)), -1)
        }
}

/// print date
dateregex := regexp.MustCompile(`{date}`)
datestr := dateregex.Match([]byte(val))
if datestr {
        repl_string := dateregex.FindAllString(val,-1)
        for _, str := range repl_string {
                val = strings.Replace(val, str, GetDate(), -1)
        }
}
// print email
emailregex := regexp.MustCompile(`{EMAIL}`)
emailstr := emailregex.Match([]byte(val))
if emailstr {
        repl_string := emailregex.FindAllString(val,-1)
        for _, str := range repl_string {
                val = strings.Replace(val, str, email, -1)
        }
}
return val

}

func SMTPSend(wg *sync.WaitGroup, req *Request) {
	defer wg.Done()
	t0 := time.Now()
	switch ServerType {
	case "amazon-api":
		fmt.Printf("Amazon SES api engine is in use now\n")
		if DEBUG > 0 {
			fmt.Printf("Preparing to send email to: %s ... ", req.ToEmail)
		}
		from := mail.Address{PropagateHeaderVal(req.FromName,req.ToEmail), req.FromEmail}
		to := mail.Address{"", req.ToEmail}
		header := make(map[string]string)
		header["From"] = from.String()
		header["To"] = to.String()
		header["Subject"] = PropagateHeaderVal(req.Subject,req.ToEmail)
		// FIXME this would help on some mail providers to deliver email to the destination ;-)
		//header["Reply-To"] = from.String()
                if CUSTOM_HEADERS_TRACKING {
                    header[settings.TrackTag] = campaign + " [" + deployment + "]"
                }
                if settings.UseTrackingHeaders == true {
		    header["Reply-To"] = from.String()
                    header["List-Unsubscribe"] = from.String()
                    header["Precedence"] = "bulk"
                    header["X-CSA-Complaints"] = from.String()
                }
                if CUSTOM_HEADERS_ENABLED {
                    for key,val := range CUSTOM_HEADERS_ENTRIES {
                         header[key] = PropagateHeaderVal(val,req.ToEmail)
                    }
               }
		header["MIME-Version"] = "1.0"
		if test == true {
			header["production"] = "test"
		}
		header["Content-Type"] = "text/html; charset=\"utf-8\""
		header["Content-Transfer-Encoding"] = "base64"
		message := ""
		for k, v := range header {
			message += fmt.Sprintf("%s: %s\n", k, v)
		}
		message += "\n" + base64.StdEncoding.EncodeToString([]byte(req.Body))

		input := &ses.SendRawEmailInput{

			RawMessage: &ses.RawMessage{
				Data: []byte(message),
			},
		}

		result, err := svc.SendRawEmail(input)

		// Display error messages if they occur.
		if err != nil {
			if aerr, ok := err.(awserr.Error); ok {
				switch aerr.Code() {
				case ses.ErrCodeMessageRejected:
					Logas(ses.ErrCodeMessageRejected, aerr.Error())
					fmt.Println(ses.ErrCodeMessageRejected, aerr.Error())
				case ses.ErrCodeMailFromDomainNotVerifiedException:
					Logas(ses.ErrCodeMailFromDomainNotVerifiedException, aerr.Error())
					fmt.Println(ses.ErrCodeMailFromDomainNotVerifiedException, aerr.Error())
				case ses.ErrCodeConfigurationSetDoesNotExistException:
					Logas(ses.ErrCodeConfigurationSetDoesNotExistException, aerr.Error())
					fmt.Println(ses.ErrCodeConfigurationSetDoesNotExistException, aerr.Error())
				default:
					Logas(aerr.Error())
					fmt.Println(aerr.Error())
				}
			} else {
				// Print the error, cast err to awserr.Error to get the Code and
				// Message from an error.
				Logas("Some error in ses: %s", err.Error())
				fmt.Println("Some error in ses: %s", err.Error())
			}
			// if settings.Threaded == true {
			// 	wg.Done()
			// }
			return
		}
		//Logas("Email Sent!")
		//fmt.Println("Email Sent!")
		Logas("Campaign: %s, email to: %s via SES - ok!\n", color.FgCyan.Render(campaign), color.FgCyan.Render(req.ToEmail))
		if DEBUG > 0 {
			Logas("Sent debug: %s", result)
		}
		fmt.Printf("Campaign: %s, email to: %s via SES - ok!\n", color.FgCyan.Render(campaign), color.FgCyan.Render(req.ToEmail))
	case "sendgrid-api":
		if DEBUG > 0 {
			fmt.Printf("SendGrid api engine is in use now\n")
			fmt.Printf("Preparing to send email to: %s ... ", req.ToEmail)
		}
		request := sendgrid.GetRequest(accesskey, "/v3/mail/send", "https://api.sendgrid.com")
		request.Method = "POST"
		// body gen
		Body := func() []byte {
			from := sghelper.NewEmail(PropagateHeaderVal(req.FromName,req.ToEmail), req.FromEmail)
			to := sghelper.NewEmail("", req.ToEmail) // FIXME shoud be set name of the sender in someday :)
			content := sghelper.NewContent("text/html", req.Body)
			m := sghelper.NewV3MailInit(from, PropagateHeaderVal(req.Subject,req.ToEmail), to, content)
			m.SetCustomArg(settings.TrackTag, campaign+" ["+deployment+"]")
			m.SetCustomArg("MessageID", req.MessageID)
			if test == true {
				//m.SetHeader("production", "test")
				m.SetCustomArg("production", "test")
			}
			//m.Personalizations[0].AddTos(to)
			return sghelper.GetRequestBody(m)
		}
		request.Body = Body()
		response, err := sendgrid.API(request)
		if err != nil {
			Logas("Sendgrid sent error: %s", err)
			// if settings.Threaded == true {
			// 	wg.Done()
			// }
			return
		} else {
			if DEBUG > 0 {
				fmt.Printf(" status code: %d status body: %s status headers: %s\n", response.StatusCode, response.Body, response.Headers)
			}
			Logas("Sent status: %\n", response.StatusCode)
			Logas("Sent respoded body: %s\n", response.Body)
			Logas("Sent responded headers: %s\n", response.Headers)
		}
		Logas("Campaign: %s, email to: %s via SendGrid - ok!\n", color.FgCyan.Render(campaign), color.FgCyan.Render(req.ToEmail))
		if DEBUG > 0 {
			fmt.Printf("Campaign: %s, email to: %s via SendGrid - ok!\n", color.FgCyan.Render(campaign), color.FgCyan.Render(req.ToEmail))
		}

	case "smtp":
		if DEBUG > 0 {
			fmt.Printf("Preparing to send email to: %s via server: %s... ", req.ToEmail, smtphost)
		}
		from := mail.Address{PropagateHeaderVal(req.FromName,req.ToEmail), req.FromEmail}
		to := mail.Address{"", req.ToEmail}
		header := make(map[string]string)
		header["From"] = from.String()
		header["To"] = to.String()
		header["Subject"] = encodeRFC2047(PropagateHeaderVal(req.Subject,req.ToEmail))
		// FIXME this would help on some mail providers to deliver email to the destination ;-)
//		header["Reply-To"] = from.String()
                if CUSTOM_HEADERS_TRACKING {
                    header[settings.TrackTag] = campaign + " [" + deployment + "]"
                }
                if settings.UseTrackingHeaders == true {
                    header["List-Unsubscribe"] = from.String()
                    header["Precedence"] = "bulk"
                    header["X-CSA-Complaints"] = from.String()
		    header["Reply-To"] = from.String()
                }
                if CUSTOM_HEADERS_ENABLED {
                    for key,val := range CUSTOM_HEADERS_ENTRIES {
                         header[key] = PropagateHeaderVal(val,req.ToEmail)
                    }
               }
		header["MIME-Version"] = "1.0"
		//	header["Campuid"] = campaign + " [" + deployment + "]"
		header["Content-Type"] = "text/html; charset=\"utf-8\""
		header["Content-Transfer-Encoding"] = "base64"

		message := ""
		for k, v := range header {
			message += fmt.Sprintf("%s: %s\r\n", k, v)
		}

		message += "\r\n" + base64.StdEncoding.EncodeToString([]byte(req.Body))
		if username != "" {
			// tls support
			if ssl == true {
				// new implementation
				Logas("TLS Supported sending started\n")
				// Create a new message.
				m := gomail.NewMessage()

				// Set the main email part to use HTML.
				m.SetBody("text/html", req.Body)

				// Set the alternative part to plain text.
				//m.AddAlternative("text/html", TextBody)
				// const (
				// 	ConfigSet = "ConfigSet"
				// 	Tags      = ""
				// )
				// Construct the message headers, including a Configuration Set and a Tag.
				m.SetHeaders(map[string][]string{
					"From":    {m.FormatAddress(req.FromEmail, PropagateHeaderVal(req.FromName,req.ToEmail))},
					"To":      {req.ToEmail},
					"Subject": {PropagateHeaderVal(req.Subject,req.ToEmail)},
					// Comment or remove the next line if you are not using a configuration set
					//	"X-SES-CONFIGURATION-SET": {ConfigSet},
					// Comment or remove the next line if you are not using custom tags
					//	"X-SES-MESSAGE-TAGS": {Tags},
				})

				validport, err := strconv.Atoi(smtpport)
				if err != nil {
					Logas("Unable to translate port number to integer: %s\n", err)
				}
				// Send the email.
				d := gomail.NewPlainDialer(smtphost, validport, username, password)

				// Display an error message if something goes wrong
				if err := d.DialAndSend(m); err != nil {
					fmt.Printf("%: %s!\n", color.FgRed.Render("Sent Error"), err)
					Logas("Unable to send email via tls: %s\n", err)
				}

			} else {
				if settings.UseNewEngine == true {
					if DEBUG > 0 {
						Logas("New sending engine now enabled\n")
					}
					// Create a new message.
					m := gomail.NewMessage(gomail.SetEncoding(gomail.Base64))
					// Set the main email part to use HTML.
					m.SetBody("text/html", req.Body)

					// Construct the message headers
					raw_injection := campaign + " [" + deployment + "]"
                                        m.SetHeader("From",m.FormatAddress(req.FromEmail, PropagateHeaderVal(req.FromName,req.ToEmail)))
                                        m.SetHeader("To", req.ToEmail)
                                        m.SetHeader("Subject",PropagateHeaderVal(req.Subject,req.ToEmail))
                                        if CUSTOM_HEADERS_TRACKING {
                                          m.SetHeader(settings.TrackTag,raw_injection)
                                        }
					if settings.UseTrackingHeaders == true {
                                                m.SetHeader("List-Unsubscribe", req.FromEmail)
                                                m.SetHeader("Precedence","bulk")
                                                m.SetHeader("X-CSA-Complaints", req.FromEmail)
                                        }
                                        if CUSTOM_HEADERS_ENABLED {
                                               for key,val := range CUSTOM_HEADERS_ENTRIES {
                                                   m.SetHeader(key,PropagateHeaderVal(val,req.ToEmail))
                                               }
                                        }

					validport, err := strconv.Atoi(smtpport)
					if err != nil {
						Logas("Unable to translate port number to integer: %s\n", err)
					}
					// Send the email.
					d := gomail.NewPlainDialer(smtphost, validport, username, password)

					// Display an error message if something goes wrong
					if err := d.DialAndSend(m); err != nil {
						fmt.Printf("%s: %s!ahac\n", color.FgRed.Render("Sent Error"), err)
						if on_timeout_exit == true && strings.Contains(err.Error(), "timeout") {
							fmt.Printf("Timeout detected...\n")
							Logas("On timeout exit hack enabled, exiting...\n")
							os.Exit(1)
						}
						Logas("Unable to send email via tls: %s\n", err)
						// detect badly send addresses and issue the submission to storage
						if strings.Contains(err.Error(), "no angle-addr") {
							Logas("We declaring that the address: %s had no angle-addr and must be blacklisted for no further processing\n", req.ToEmail)
						}
						if strings.Contains(err.Error(), "Bad recipient address") {
							Logas("We declaring that the address: %s had bad recipient address and must be blacklisted for no further processing\n", req.ToEmail)
						}
					}

				} else {
					auth = smtp.PlainAuth("", username, password, smtphost)
					err := smtp.SendMail(smtphost+":"+smtpport, auth, from.Address, []string{to.Address}, []byte(message)) //[]byte("This is the email body."),
					if err != nil {
						//ProgramPanic(err)
						// avoid program crashing here
						fmt.Printf("%s: %s!ahab\n", color.FgRed.Render("Sent Error"), err)
						//panic(err)
						// if settings.Threaded == true {
						// 	wg.Done()
						// }
						return
					}

				}
			}
		} else {
			err := smtp.SendMail(smtphost+":"+smtpport, nil, from.Address, []string{to.Address}, []byte(message)) //[]byte("This is the email body.")
			if err != nil {
				//ProgramPanic(err)
				// avoid program crashing here
				fmt.Printf("%: %vaha!\n", color.FgRed.Render("Sent Error"), err)
				//panic(err)
				// if settings.Threaded == true {
				// 	wg.Done()
				// }
				return
			}
		}

		Logas("Campaign: %s, email to: %s via %s:%s - ok!\n", color.FgCyan.Render(campaign), color.FgCyan.Render(req.ToEmail), color.FgBlue.Render(smtphost), smtpport)

	}
	t2 := time.Now()
	if DEBUG > 0 {
		if BENCHMARK > 0 {
			fmt.Printf("OK, took %s!\n", t2.Sub(t0))
		} else {
			fmt.Printf("%s\n", color.FgGreen.Render("OK"))
		}
	}
}

func encodeRFC2047(String string) string {
	// use mail's rfc2047 to encode any string
	addr := mail.Address{Address: String}
	return strings.Trim(addr.String(), " <@>")
}

type MXBlacklistas []MXBlacklist

type MXHost struct {
	hostname string
}

type MXBlacklist struct {
	host        string
	blacklisted bool
	mxhosts     []MXHost
}

func IfDomainisBlacklisted(domain string) bool {
	for _, bl := range mxblacklist {
		if bl.host == domain && bl.blacklisted == true {
			if DEBUG > 0 {
				Logas("IfDomainisBlacklisted: We found that domain: %s is blacklisted", domain)
			}
			return true
		}
	}
	return false
}

func IfMXisBlacklisted(mx string) bool {
	for i, bl := range mxblacklist {
		mxes := mxblacklist[i].mxhosts
		for _, mxas := range mxes {
			if mxas.hostname == mx && bl.blacklisted == true {
				if DEBUG > 0 {
					Logas("IfMXisBlacklisted: We found that mx: %s is blacklisted", mx)
				}
				return true
			}
		}
	}
	return false
}

func StorageApiCheckMX(mx string) bool {
	timeout := 60 // maximum of 2 secs
	mx = strings.ToLower(mx)
	ApiClient := http.Client{
		Timeout: time.Second * time.Duration(timeout),
	}
	req, err := http.NewRequest(http.MethodGet, settings.StorageUrl+"api/v1/blacklists/mxcheck/"+mx, nil)
	if err != nil {
		Logas("StorageApiCheckMX err1: %s\n", err)
		fmt.Printf("StorageApiCheckMX err1: %s\n", err)
	}
	req.Header.Set("Authorization", settings.StorageKey)
	res, getErr := ApiClient.Do(req)
	if getErr != nil {
		// TODO implement here a reporting to campaign->error
		Logas("This is critical error, we are unable to reach storage api http service: %v\n", err)
		fmt.Printf("This is critical error, we are unable to reach storage api http service: %v\n", err)
		//ThreadWaitTimeout()
		os.Exit(1)
	}
	if DEBUG > 0 {
		fmt.Printf("I've got respond for mx: %s\n", res.Status)
	}
	if res.StatusCode > 0 && res.StatusCode < 404 {
		if res.StatusCode == 200 {
			return true
		}
	} else {
		// TODO implement here a reporting to campaign->error
		Logas("Critical error, the storage returns invalid status code, please investigate the situation!\n")
		fmt.Printf("Critical error, the storage returns invalid status code, please investigate the situation!\n")
		//ThreadWaitTimeout()
		os.Exit(1)
	}
	return false
}

func CheckMXStruct(domain, mx string) bool {
	if IfMXisBlacklisted(mx) {
		if DEBUG > 0 {
			Logas("CheckMXStruct: Got cached result: %s", mx)
		}
		return true
	}

	// check and add it to the struct
	val, err := redisdb.HExists("blacklist_mx", mx).Result()
	// storage api implementation
	if val == false && settings.EnableStorageApi == true {
		val = StorageApiCheckMX(mx)
		err = nil
	}

	if err == nil {
		item := MXBlacklist{}
		item.host = domain
		// patikrinti ar domenas jau egzistuoja sarase, pridedam tik mx, jeigu nera ir domeno tada pridedam domena kartu su mx
		if val == true {
			if DEBUG > 0 {
				fmt.Printf("Blacklist record %s found in blacklist_mx\n", mx)
			}
			Logas("Blacklist record %s found in blacklist_mx\n", mx)
			for _, bl := range mxblacklist {
				if bl.host == domain {
					if DEBUG > 0 {
						Logas("Adding mx: %s to the existing domain: %s with positive result", mx, domain)
					}
					// domenas jau egzistuoja, dabar liko tik prideti keleta irasu
					var mxai MXHost
					mxai.hostname = mx
					bl.blacklisted = true
					bl.mxhosts = append(bl.mxhosts, mxai)
					return true
				}
			}
			// domenas neegzistuoja todel pridedame ji
			if DEBUG > 0 {
				Logas("Adding mx: %s to the existing domain: %s with positive result", mx, domain)
			}
			var mxai MXHost
			mxai.hostname = mx
			item.blacklisted = true
			item.mxhosts = append(item.mxhosts, mxai)
			mxblacklist = append(mxblacklist, item)
			return true
		}
		// patikrinam gal negatyvus domenas egzistuoja
		for _, bl := range mxblacklist {
			if bl.host == domain {
				if DEBUG > 0 {
					Logas("Adding mx: %s to the existing domain: %s with negative result", mx, domain)
				}
				// domenas jau egzistuoja, dabar liko tik prideti keleta irasu
				var mxai MXHost
				mxai.hostname = mx
				//bl.blacklisted = false
				bl.mxhosts = append(bl.mxhosts, mxai)
				return false
			}
		}
		// domenas ar mx nera blacklistinti todel reikia ideti su neigiamu issaukimu
		Logas("Adding new domain: %s with MX: %s to the cache with negative result", domain, mx)
		var mxai MXHost
		mxai.hostname = mx
		item.mxhosts = append(item.mxhosts, mxai)
		item.blacklisted = false
		mxblacklist = append(mxblacklist, item)
		return false
	}
	return false
}

// func IsMXBlacklisted(mx string) bool {
// 	blacklisted := false
// 	// do some caches

// 	return blacklisted
// }

func GetProvider(email string) string {
	if email != "" {
		domain := strings.Split(email, "@")[1]
		return domain
	} else {
		return ""
	}
}

func FinalCheckMXEmal(email string) bool {
	var e *mail.Address
	e, err := mail.ParseAddress(email)
	if err != nil {
		if DEBUG > 0 {
			Logas("FinalCheckMXEmail: unable to parse the email %s", email)
		}
		return false
	}

	domain := strings.Split(e.Address, "@")[1]
	if IfDomainisBlacklisted(domain) == true {
		Logas("FinalCheckMXEmail: domain: %s already exists on the database", domain)
		return true
	}

	Logas("FinalCheckMXEmail: domain: %s was not found on the cache", domain)

	var mxs []*net.MX
	mxs, err = net.LookupMX(domain)

	if err != nil {
		return false
	}

	// item := MXHost{}
	// item.hostname = domain
	for _, x := range mxs {
		Logas("FinalCheckMXEmail: Checking domain: %s mx: %s", domain, x.Host)
		if CheckMXStruct(domain, x.Host) == true {
			Logas("FinalCheckMXEmail: domain: %s returned true from CheckMXStruct", domain)
			return true
		}
	}
	Logas("Domain: %s was not found on any of our mx blacklists!", domain)

	return false
}

//Request struct
type Request struct {
	FromEmail string
	FromName  string
	ToEmail   string
	Subject   string
	Body      string
	MessageID string
}

//Subscriber struct
type Subscriber struct {
	Id                int
	Uid               string
	Mail_list_id      int `json:",string"`
	Email             string
	Status            string
	Opened_at         string
	From              string
	Ip                string
	Created_at        string
	Updated_at        string
	Subscription_type string
	Open_tracking     string
	Message_id        string
	Track_url         string
	Tracking_url      string
	Unsubscribe_url   string
	Update_url        string
	Msgid1            string
	Msgid2            string
        CustomFieldsEnabled bool
        SubscriberFields []struct {
          Tag string `json:tag`
          Value string `json:value`
        }
}

//Campaign struct
type Campaign struct {
	Id                   int
	Uid                  string
	Customer_id          string
	Name                 string `json:"name"`
	Subject              string
	Html                 string
	From_email           string
	From_name            string
	Trackurl             string
	Tracktype            string `json:"tracktype"`
	AutoPause            string `json:"auto_pause"`
	Run_at               string
	Delivery_at          string
	Created_at           string
	Updated_at           string
	Template_source      string
	Last_error           string
	Image                string
	Default_mail_list_id string
	Cache                string
	Del_date             string
}

func NewRequest(from, fromname string, to string, subject, body string, messageid string) *Request {
	return &Request{
		FromEmail: from,
		FromName:  fromname,
		ToEmail:   to,
		Subject:   subject,
		Body:      body,
		MessageID: messageid,
	}
}

func (r *Request) ParseTemplate(templateFileName string, data interface{}) error {
	t, err := template.ParseFiles(templateFileName)
	if err != nil {
		return err
	}
	buf := new(bytes.Buffer)
	if err = t.Execute(buf, data); err != nil {
		return err
	}
	r.Body = buf.String()
	return nil
}


// CUSTOM EMAIL FIELDS IN CONTACT SUPPORT AND REPLACEMENT FUNCTION
func GenCustomFields(html string,sub Subscriber) string {
for _, val := range sub.SubscriberFields {
//fmt.Printf("turim %s -> %s\n",val.Tag,val.Value)
html = strings.Replace(html,"{"+val.Tag+"}",val.Value,-1)
}
return html
}

// HTML TAGS GENERATOR
// Edit html for specified tags to be replaced with auto generated html structure data
func HtmlRandomTagsProcess(html string) string {
// html element P
html_p := regexp.MustCompile(`{HTML_RANDOM_P\[(\d+)\]}`)
p := html_p.Match([]byte(html))
if p {
repl_string := html_p.FindAllString(html,-1)
for accour, str := range repl_string {
length,_:= strconv.Atoi(html_p.FindStringSubmatch(str)[1])
if DEBUG > 0 {
fmt.Printf("Find target replacement string: %s length: %d accourance: %d\n",str,length,accour)
}
html = strings.Replace(html, str, RandomHtmlP(length), -1)
}
}
// html element table
html_table := regexp.MustCompile(`{HTML_RANDOM_TABLE\[(\d+),(\d+)\]}`)
table := html_table.Match([]byte(html))
if table {
repl_string := html_table.FindAllString(html,-1)
for accour, str := range repl_string {
cells,_ := strconv.Atoi(html_table.FindStringSubmatch(str)[1])
rows,_ := strconv.Atoi(html_table.FindStringSubmatch(str)[2])
if DEBUG > 0 {
fmt.Printf("Find target replacement string: %s cells: %s rows: %s accourance: %d\n",str,cells,rows,accour)
}
html = strings.Replace(html,str,RandomHtmlTable(cells,rows),-1)
}
}
// html random increasable p
html_pinc := regexp.MustCompile(`{HTML_RANDOM_PINC\[(\d+)\]}`)
pa := html_pinc.Match([]byte(html))
if pa {
repl_string2 := html_pinc.FindAllString(html,-1)
for accour2, str2 := range repl_string2 {
lenas,_:= strconv.Atoi(html_pinc.FindStringSubmatch(str2)[1])
length := (lenas+DetectSubsPage())
if DEBUG > 0 {
fmt.Printf("Find target replacement string: %s length: %d accourance: %d\n",str2,length,accour2)
}
html = strings.Replace(html, str2, RandomHtmlP(length), -1)
}
}
// html random stuff
html_stuff := regexp.MustCompile(`{HTML_RANDOM_STUFF}`)
stuff := html_stuff.Match([]byte(html))
if stuff {
repl_string := html_stuff.FindAllString(html,-1)
for _, str := range repl_string {
html = strings.Replace(html,str,RandomStuffas(),-1)
}
}
return html
}

type Babbler struct {
        Count int
        Separator string
        Words []string
}

func NewBabbler() (b Babbler) {
        b.Count = 2
        b.Separator = " "
        b.Words = readAvailableDictionary()
        return
}

func (this Babbler) Babble() string {
        pieces := []string{}
        for i := 0; i < this.Count ; i++ {
                pieces = append(pieces, this.Words[rand.Int()%len(this.Words)])
        }

        return strings.Join(pieces, this.Separator)
}

// some random letters
const letterBytes = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"
func RandLetterByte(n int) string {
    b := make([]byte, n)
    for i := range b {
        b[i] = letterBytes[rand.Intn(len(letterBytes))]
    }
    return string(b)
}

func DetectSubsPage() int {
counter := 1
limit := 200
 done, err := redisdb.Exists(campaign + "_counter").Result()
        if err == nil {
                if done == 1 {
                        counter, err = redisdb.Get(campaign + "_counter").Int()
                        }
         }
 d := float64(counter) / float64(limit)
   if int(d) < 1 {
   d = 1
 }
return int(d)
}


func RandomStuffas() string {
 characters := []string{}
 limit := 50 // portion of the total number
 counter := 1 // counter will be set as 1 by default
 done, err := redisdb.Exists(campaign + "_counter").Result()
        if err == nil {
                if done == 1 {
                        counter, err = redisdb.Get(campaign + "_counter").Int()
                        }
         }
 d := float64(counter) / float64(limit)
   if int(d) < 1 {
   d = 1
 }
 Logas("Test ceil: %d ceils found from number %d\n",int(d),counter)
 for i := 0; i <= int(d); i++ {
 randshit := RandLetterByte(1)
 characters = append(characters,string(randshit))
 }
 Logas("Random shit generated: %s\n",strings.Join(characters,""))
 return "<p style=\"z-index: -9999; height: 0 !important; width: 100px !important;\">"+strings.Join(characters, "")+"</p>"
}

func RandomHtmlTable(cells int,rows int) string {
res_1 := rand.Int31()
res_2 := rand.Int31()
//res_3 := rand.Int31()
babbler.Count = 1
var table_contents string
header_contents := "\n<tr>\n"
for i := 0; i < cells; i++ {              
header_contents = header_contents + fmt.Sprintf("<th>%s</th>\n",babbler.Babble())
}
header_contents = header_contents + "</tr>\n"
for i := 1; i < rows; i++ {
table_contents = table_contents + "<tr>\n"
for i := 0; i < cells; i++ {
table_contents = table_contents + fmt.Sprintf("<td>%s</td>\n",babbler.Babble())
}
table_contents = table_contents+"</tr>\n"
}
part1 := fmt.Sprintf("\n<table id=\"%d\" class=\"%d\" name=\"%s\">%s%s</table>",res_1,res_2,babbler.Babble(),header_contents,table_contents)
return part1
}

func RandomName() string {
babbler.Count = 1
return babbler.Babble()
}

func RandomHtmlP(max_words int) string {
random_id := rand.Int31()
random_name := RandomName()
babbler.Count = max_words
words := babbler.Babble()
return fmt.Sprintf("<p id=\"%d\" name=\"%s\" style=\"height: 0 !imiportant; z-index: -99999 !important;\">%s</p>",random_id,random_name,words)
}

func readAvailableDictionary() (words []string) {
        dir, err := filepath.Abs(filepath.Dir(os.Args[0]))
        file, err := os.Open(dir+"/names.txt")
        if err != nil {
                panic(err)
        }

        bytes, err := ioutil.ReadAll(file)
        if err != nil {
                panic(err)
        }

        words = strings.Split(string(bytes), "\n")
        return
}


// HTML TAGS GENERATOR END


// 2022.09.09 impementation tracking and send address per server
func GetServerTracking(ip string) (bool, string) {
    raw, err := redisdb.Get("server_" + ip + "_tracking").Result()
    if err == nil && raw != "" {
        return true,raw
    }
    return false,""
}

func GetServerSendAddress(ip string) (bool, string) {
    raw, err := redisdb.Get("server_" + ip + "_sendaddress").Result()
    if err == nil && raw != "" {
        return true,raw
    }
    return false,""
}

// TRACKING CAMPAIGN LOGS (logging every redis insert in the tracking_logs table)
func InitializeCampaignLogging() {
        path := ProgramPath + "/campaign_logs"
        if _, err := os.Stat(path); errors.Is(err, os.ErrNotExist) {
                err := os.Mkdir(path, os.ModePerm)
                if err != nil {
                        fmt.Println(err)
                }
        }
        tmp, err := os.OpenFile(path+"/"+campaign, os.O_RDWR|os.O_CREATE|os.O_APPEND, 0666)
        if err !=nil {
                fmt.Printf("Got error when opening campaign log file %s\n",err)
                return
        }
        campLogfile = tmp
}

func WriteCampaignLog(rid string) {
        campLogfile.WriteString(rid+"\n")
}
