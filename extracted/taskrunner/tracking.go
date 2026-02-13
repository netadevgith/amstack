/*
2022.10.24 Tracking link implemtation in task runner (c) e1z0
Things are already working
Images, css, js, build-in server for serving static files
Tracking types working: "New Tracking"
Functionality: click,open,unsubscribe,abuse
Host hiding from bots and another sources
TODO:
Implement link blacklisting
Implement user agent blacklisting
Host:Port autodetection and fix
*/
package main

import (
	"encoding/json"
	"errors"
	"fmt"
	"io/ioutil"
	"log"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"

	router2 "github.com/fasthttp/router"
	"github.com/oschwald/geoip2-golang"
	"github.com/valyala/fasthttp"
)

var (
	default_httpd_port = 8555
	geodb *geoip2.Reader
	Algorithm          map[string]string
	StaticFilesPath = "../public_html/public/source"
	StaticformsPath = "./httpd"
	lock = &sync.Mutex{}
    loggers *LOGGER
	singleInstance *single
)

type single struct {
}

func Index(ctx *fasthttp.RequestCtx) {
	ctx.Redirect("/report",fasthttp.StatusMovedPermanently)
}

func Robots(ctx *fasthttp.RequestCtx) {
	content := "User-agent: *\nDisallow: /\n"
	ctx.SetContentType("text/plain")
	ctx.SetBody([]byte(content))
	ctx.SetStatusCode(fasthttp.StatusOK)
 }

 func Report(ctx *fasthttp.RequestCtx) {
	content, err := ioutil.ReadFile(ProgramPath+"/httpd/forms/abuse.html")
	if err != nil {
		log.Fatal(err)
	}
	//content = []byte(strings.Replace(string(content), "{ERROR}", erroras, -1))
	ctx.SetContentType("text/html")
	ctx.SetBody(content)
	ctx.SetStatusCode(fasthttp.StatusOK)
}

// test curl -X POST -H "Authorization: 1122" bitiqhome.site/h3r311nf0
func Infosic(ctx *fasthttp.RequestCtx) {
	method := ctx.Method()
	if string(method) == "POST" {
		auth := ctx.Request.Header.Peek("Authorization")
		if string(auth) == "1122" {
			output := ""
			remoteip := GetIpAddressFromHeader(ctx)
			trackdom := GetTrackDomainFromHeader(ctx)
			lokacija := ReturnLocation(remoteip)
			log.Printf("We have authorized some api call to fetch some statistics")
			output += fmt.Sprintf("Remote IP: %s Tracking domain: %s Location: %s\n",remoteip,trackdom,lokacija)
			output += fmt.Sprintf("X-Forwarded-For: %s\n",ctx.Request.Header.Peek("X-Forwarded-For"))
			output += fmt.Sprintf("Internal (our) header: %s\n",ctx.Request.Header.Peek("Internal"))
			ctx.SetContentType("text/text")
            ctx.SetBody([]byte(output))
			ctx.SetStatusCode(fasthttp.StatusOK)
		}
	}
}

func ReceiveAbuse(ctx *fasthttp.RequestCtx) {
	method := ctx.Method()
	if string(method) == "POST" {
		loggers.abuse.Printf("We got abuse submission")
		abuseform := ""
		remoteip := GetIpAddressFromHeader(ctx)
		trackdom := GetTrackDomainFromHeader(ctx)
		lokacija := ReturnLocation(remoteip)
		// user submitted data
		name := string(ctx.FormValue("name"))
		if name != "" {
			abuseform = abuseform + " name: "+name
		}
		email := string(ctx.FormValue("email"))
		if email != "" {
			abuseform = abuseform + " email: "+email
		}
		title := string(ctx.FormValue("title"))
		if title != "" {
			abuseform = abuseform + " title: "+title
		}
		source_ips :=  string(ctx.FormValue("source_ips"))
		if source_ips != "" {
			abuseform = abuseform + " source_ips: "+source_ips
		}
		destination_ips := string(ctx.FormValue("destination_ips"))
		if destination_ips != "" {
			abuseform = abuseform + " destination_ips: "+destination_ips
		}
		urls := string(ctx.FormValue("urls"))
		if urls != "" {
			abuseform = abuseform + " urls: "+urls
		}
		comments := string(ctx.FormValue("comments"))
		if comments != "" {
			abuseform = abuseform + " comments: "+comments
		}
		abuseform = abuseform + fmt.Sprintf(" trackdomain: %s ip: %s location: %s",trackdom,remoteip,lokacija)
		if email != "" {
                        // submission to remote storage api
                       StorageApiBlacklistAdd(email,3)
					   RedisHSet("blacklist_abuse",email,fmt.Sprintf("%s",abuseform))
					   InsertSQL("INSERT INTO blacklist_abuse (email,reason,admin_id,customer_id)",[]string {email,abuseform,"1","1"})
		} else {
			loggers.abuse.Printf("Got error in abuse form submission maybe required email field is empty\n")
		}
		loggers.abuse.Printf("Got abuse form submitted: %s\n",abuseform)
		ctx.SetContentType("text/json")
		ctx.SetBody([]byte(`{ "result": "success" }`))
		ctx.SetStatusCode(fasthttp.StatusOK)
	}
	return
}

// 2022.10.24 tested and working, confirmed
func TrackingOpen(redis_id string,data TrackingLog, remote RemoteDetails) {
	if DEBUG > 0 {
		log.Printf("TrackingOpen function: %s %s\n",data,remote)
	}
	log_output := ""
	if data.Test == false {
		if data.Opened == false {
		data.Opened = true
		tmpjson, err := json.Marshal(&data)
	    if err != nil {
		     loggers.debug.Printf("Problem in serializing data for open tracking log %s err: %s\n", redis_id,err)
			 return
		} 
		RedisHSet("tracking_logs",redis_id,string(tmpjson))
		redisdb.Incr(data.Campuid+"_openers")
		tracking_info, err := GetTrackingInfo(data.TrackId)
		if err == nil {
		 // here we should insert some info into the required fields, open_log, trackingas and storage
	     reverse := GetReverse(data.Server)
		 date_added := DateNow()
		 dataItem := UniversalStorageTracking{Email: tracking_info.Email, Ip_address: remote.RemoteIP, Server_ip: data.Server, Server_ptr: reverse, User_agent: remote.UserAgent, Location: remote.Location, Date_added: date_added, Deployment: DEPLOYMENT, Domain: remote.TrackDomain, Campaign: data.Campuid, Maillist: tracking_info.MailList}
		 log_output += fmt.Sprintf("%#v\n",dataItem)
		 //log.Printf("%#v\n",dataItem)
		 ats := SubmitToStorage(0,dataItem)	
		 if ats == false {
			log_output += "Unable to transfer information to the remote storage\n"
		 }
		 ats = SubmitTrackingas(0,dataItem)
		 if ats == false {
			log_output += "Unable to transfer information to the remote trackingas\n"
		 }
		 err = InsertTrackingOpen(data,remote)
		 if err != nil {
			log_output += fmt.Sprintf("Unable to insert open tracking log: %s, maybe assigned campaign is already deleted!\n",err)
		 }
         log_output += "----------------------------------------------------------------------------------------\n"
		 loggers.open.Println(log_output)
		} else {
			loggers.debug.Printf("Unable to get tracking log for id: %s from mysql database, maybe the campaign records are already deleted\n",data.TrackId)
		}
		} else {
			loggers.debug.Printf("Tracking id: %s already registered as opened...\n",data.TrackId)
		}

	} else {
		loggers.debug.Printf("Tracking id: %s is a test email\n",data.TrackId)
	}
}

// 2022.10.24 tested and working
func TrackingClick(redis_id string,data TrackingLog, remote RemoteDetails, link string, eruoras error) {
	log.Printf("TrackingClick function: %s %s %s %s\n",data,remote,link,eruoras)
	if eruoras != nil {
		loggers.debug.Printf("No valid tracking log found for click tracking link %s, only these details are known: %#v %#v\n'",link,data,remote)
		// there was error when retrieving the tracking log, so it means that the tracking log does not exist and the compaign seems to be
		// deleted already, so we only redirect to the specified url only and thats all
	} else {
		// valid tracking log exists so we can proceed in the whole process
		if data.Test == false {
			log_output := ""				
			tracking_info, err := GetTrackingInfo(data.TrackId)
			if err == nil {
				if data.Clicked == false {
					log_output += "First click from email detected\n"
					data.Clicked = true
					tmpjson, err := json.Marshal(&data)
					if err != nil {
						loggers.debug.Printf("Problem in serializing data for click tracking log %s err: %s\n", redis_id,err)
						 //return
					} else {
						RedisHSet("tracking_logs",redis_id,string(tmpjson))
						redisdb.Incr(data.Campuid+"_clickers")
					}
				} 
			 	// here we should insert some info into the required fields, open_log, trackingas and storage
			 	reverse := GetReverse(data.Server)
			 	date_added := DateNow()
			 	dataItem := UniversalStorageTracking{Email: tracking_info.Email, Ip_address: remote.RemoteIP, Server_ip: data.Server, Server_ptr: reverse, User_agent: remote.UserAgent, Location: remote.Location, Date_added: date_added, Deployment: DEPLOYMENT, Domain: remote.TrackDomain, Campaign: data.Campuid, Maillist: tracking_info.MailList}
			 	log_output += fmt.Sprintf("%#v\n",dataItem)
			 	//log.Printf("%#v\n",dataItem)
			 	ats := SubmitToStorage(1,dataItem)	
			 	if ats == false {
					log_output += "Unable to transfer information to the remote storage\n"
			 	}
			 ats = SubmitTrackingas(1,dataItem)
			 if ats == false {
				log_output += "Unable to transfer information to the remote trackingas\n"
			 }
			 err = InsertTrackingClick(data,remote,link)
			 if err != nil {
				log_output += fmt.Sprintf("Unable to insert click tracking log: %s, maybe assigned campaign is already deleted!\n",err)
			 }
			 log_output += fmt.Sprintf("Redirect target: %s\n",link)
			 log_output += "----------------------------------------------------------------------------------------\n"
			 loggers.click.Println(log_output)
			} else {
				loggers.debug.Printf("Unable to get tracking log for id: %s from mysql database, maybe the campaign records are already deleted\n",data.TrackId)
			}
		} else {
			loggers.debug.Printf("Tracking id: %s link: %s is a test email: %#v %#v\n",data.TrackId,link,data,remote)
		}
	}

}

// 2022.10.24 tested and working fine
func UnsubscribeClick(data TrackingLog, remote RemoteDetails) {
	if DEBUG > 0 {
		log.Printf("UnsubscribeClick function: %s %s\n",data,remote)
	}
	log_output := ""
	tracking_info, err := GetTrackingInfo(data.TrackId)
	if err != nil {
		log.Printf("Unable to retrieve tracking information for unsubscribe logs\n")
		return
	}
	log_output += fmt.Sprintf("Unsubscribe clicked: %#v %#v\n",data,remote,tracking_info)
    err = InsertUnsubscribeLog(tracking_info,data,remote)
	if err != nil {
		log_output += fmt.Sprintf("Unable to insert unsubscribe log, %s\n",err)
	}
	log_output += "------------------------------------------------------------\n"
	loggers.unsubscribe.Printf(log_output)
}

func ReturnTrackingItem(ida string) (TrackingLog,error) {
	item := TrackingLog{}
	res, err := redisdb.HGet("tracking_logs",ida).Result()
	if err == nil {
		err = json.Unmarshal([]byte(res), &item)
		if err == nil {
			return item,err
		}
	}
	return item,err
}

func ReturnLinkItem(ida string) (string,error) {
	res, err := redisdb.HGet("campaigns_links",ida).Result()
	return res,err
}

func CustomHttpRequest(ctx *fasthttp.RequestCtx) {
	//fmt.Fprintf(ctx, "Hello, %s!\n", ctx.UserValue("name"))
	// print remote ip and request to the log
	location := fmt.Sprint(ctx.UserValue("pass"))
	remoteip := GetIpAddressFromHeader(ctx)
	trackdom := GetTrackDomainFromHeader(ctx)
	lokacija := ReturnLocation(remoteip)
	remotas := RemoteDetails{RemoteIP: remoteip, UserAgent: string(ctx.UserAgent()), TrackDomain: trackdom,Location: lokacija}
    if DEBUG > 0 {
		log.Printf("Links web request: %s from: %s location: %s tracking dom: %s", location, remoteip,lokacija,trackdom)
	}
    // first check the local file if it's file then load it!
	// here we should strip // and other symbols from path of location, there can only be filename and extension for file
	continue_loading := false
	if _, err := os.Stat(StaticFilesPath+"/"+location); err == nil {
		// file exists
		if DEBUG > 0 {
			log.Printf("File was found on the server %s\n",location)
		}
	    content, err := ioutil.ReadFile(StaticFilesPath+"/"+location)
		if err != nil {
			log.Fatal(err)
		}
		extension := strings.ToLower(filepath.Ext(location))
		if extension != "" {
		switch extension {
		case "jpg":
			ctx.SetContentType("image/jpeg")
		case "jpeg":
			ctx.SetContentType("image/jpeg")
		case "png":
			ctx.SetContentType("image/png")
		case "gif":	
			ctx.SetContentType("image/gif")
		default:
			ctx.SetContentType("image/jpeg")
		}
		ctx.SetBody(content)
		ctx.SetStatusCode(fasthttp.StatusOK)
		return
		}
	  } else if errors.Is(err, os.ErrNotExist) {
		// path/to/whatever does *not* exist
	    continue_loading = true
	  } else {
		// Schrodinger: file may or may not exist. See err for details.	  
		// Therefore, do *NOT* use !os.IsNotExist(err) to test for file existence
		log.Printf("Schrodinger: file may or may not exist. See err for details %s.\n",err)
	    continue_loading = true
	  }
      // TODO here we should make url blacklisting, disabled links feature

	  if continue_loading { 
		if DEBUG > 0 {
			log.Printf("File was not found proceeding further loading...\n")  
		}
		// unhash
		var link_decoded string
		// check if it's open tracking
		if strings.ToLower(filepath.Ext(location)) == ".gif" {
			base_path := strings.Split(location, ".")[0]
			link_decoded = UnhashIt(base_path)
			if DEBUG > 0 {
				log.Printf("Links possible open tracking link opened: %s\n",link_decoded)
			}
			track, err := ReturnTrackingItem(link_decoded)
			if err == nil {
				go TrackingOpen(link_decoded,track,remotas)
				ctx.SetContentType("image/gif")
				ctx.SetStatusCode(fasthttp.StatusOK)
				return
			} else {
				Log.Printf("Links possible open tracking link opened: %s but tracking_logs item in redis was not found: %s\n",link_decoded,err)
			}
		} else {
			// there must be click tracking...
			link_decoded := UnhashIt(location)
			log.Printf("decoded url for click link: %s\n",link_decoded)
			if link_decoded != "" {
				if strings.Contains(strings.ToLower(link_decoded),"u") {
					// this is the link
					split_str := strings.Split(strings.ToLower(link_decoded), "u")
					trackid := split_str[0]
					link := StripSpecCharsFromLink(split_str[1])
					if trackid != "" && link != "" {
						track := TrackingLog{}
						track, err := ReturnTrackingItem(trackid)
						// spawn the thread to track link click
						url, err := ReturnLinkItem(link)
					    go TrackingClick(trackid,track,remotas,url,err)
						//url, err := ReturnLinkItem(link)
						if err == nil {
							loggers.click.Printf("Redirecting to: %s\n",url)
							ctx.Redirect(url, 301)
							return
						} else {
							log.Printf("Absurdish error come here, we can't redirect to non existen link: %s\n",err)
						}

					}
				}
			}
				// if alphanumeric this is the unsubscribe link
				is_alphanumeric := regexp.MustCompile(`^[0-9]*$`).MatchString(link_decoded)
				if is_alphanumeric{
					track, err := ReturnTrackingItem(link_decoded) 
					if err == nil {
						go UnsubscribeClick(track,remotas)
					}
						content, err := ioutil.ReadFile(ProgramPath+"/httpd/forms/unsubscribe.html")
						if err != nil {
							log.Fatal(err)
						}
						ctx.SetContentType("text/html")
						ctx.SetBody(content)
						ctx.SetStatusCode(fasthttp.StatusOK)
						return
				}
 				
			}
		}
		loggers.url.Printf("URL: %s Client: %#v\n",location,remotas)
		//log.Printf("If you see this text, then seems like programmer slept over his work :D\n")
		//loggers.http.Printf("Some unrecognizable request, must be bot or some crawler, redirecting to dummy page...\n")
		ctx.Redirect("https://google.com", 301)
}



func TrackingServer() {
	CreateLogs()
	log.Printf("Internal http server for link tracking have been enabled!\n")
	LoadDeps()
	router := router2.New()
	router.GET("/", Index)
	router.GET("/abuse",Report)
    router.GET("/robots.txt",Robots)
	router.POST("/api/v2/reportdone",ReceiveAbuse)
	router.POST("/h3r311nf0",Infosic)
	router.GET("/{pass}",CustomHttpRequest)

	fs := &fasthttp.FS{
		Root:               StaticformsPath,
		IndexNames:         []string{"index.html"},
		GenerateIndexPages: false,
		Compress:           false,
		AcceptByteRange:    false,
	}
	fsHandler := fs.NewRequestHandler()
    router.GET("/css/{file}",func(w *fasthttp.RequestCtx) {
		fsHandler(w)
    })
	router.GET("/js/{file}",func(w *fasthttp.RequestCtx) {
		fsHandler(w)
    })
	router.GET("/images/{file}",func(w *fasthttp.RequestCtx) {
		fsHandler(w)
    })

	log.Printf("Binding web service to 0.0.0.0:%d...\n", http_serv_port)
	s := &fasthttp.Server{
		Handler:            CombinedColored(router.Handler),
		Name:               "nginx/1.10.3", // we will use some fake identity
		MaxRequestBodySize: 32 << 20,
	}
	s.ListenAndServe(fmt.Sprintf("0.0.0.0:%d", http_serv_port))
}

// structs

type TrackingLog struct {
	TrackId string `json:"TrackId"`
	Server string `json:"Server"`
	Campuid string `json:"Campuid"`
	Suburl string `json:"Suburl"`
	Test bool `json:"Test"`
	Opened bool `json:"Opened"`
	Clicked bool `json:"Clicked"`
}

type RemoteDetails struct {
	RemoteIP string `json:"remoteip"`
	UserAgent string `json:"useragent"`
	TrackDomain string `json:"trackdomain"`
	Location string `json:"location"`
}

type LOGGER struct {
	debug *log.Logger
	http  *log.Logger
	open  *log.Logger
	click *log.Logger
	unsubscribe *log.Logger
	abuse *log.Logger
	url   *log.Logger
}

// utils

func GetLoggerInstance() *LOGGER {
    if singleInstance == nil {
        lock.Lock()
        defer lock.Unlock()
        if loggers == nil {
            log.Println("Creating LOGGER instance now.")
            loggers = &LOGGER{}
        } else {
            log.Println("LOGGER instance already created.")
        }
    } else {
        log.Println("LOGGER instance already created.")
    }
    return loggers
}

func CreateLogs() {
	loggers := GetLoggerInstance()
	// debug log
    f, err := os.OpenFile("logs/debug.log", os.O_RDWR|os.O_CREATE|os.O_APPEND, 0666)
    if err != nil {
        fmt.Println("debug log file not created", err.Error())
    }
    loggers.debug = log.New(f, "[DEBUG]", log.Ldate|log.Ltime|log.Lmicroseconds)
    loggers.debug.Println("Debug log started")
	// http log
	f, err = os.OpenFile("logs/http.log", os.O_RDWR|os.O_CREATE|os.O_APPEND, 0666)
    if err != nil {
        fmt.Println("http log file not created", err.Error())
    }
    loggers.http = log.New(f, "[HTTP]", log.Ldate|log.Ltime|log.Lmicroseconds)
    loggers.http.Println("Http log started")
	// open log
	f, err = os.OpenFile("logs/open.log", os.O_RDWR|os.O_CREATE|os.O_APPEND, 0666)
    if err != nil {
        fmt.Println("open log file not created", err.Error())
    }
    loggers.open = log.New(f, "[OPEN]", log.Ldate|log.Ltime|log.Lmicroseconds)
    loggers.open.Println("Open log started")
	// click log
	f, err = os.OpenFile("logs/click.log", os.O_RDWR|os.O_CREATE|os.O_APPEND, 0666)
    if err != nil {
        fmt.Println("click log file not created", err.Error())
    }
    loggers.click = log.New(f, "[CLICK]", log.Ldate|log.Ltime|log.Lmicroseconds)
    loggers.click.Println("Click log started")
	// unsubscribe log
	f, err = os.OpenFile("logs/unsubscribe.log", os.O_RDWR|os.O_CREATE|os.O_APPEND, 0666)
    if err != nil {
        fmt.Println("unsubscribe log file not created", err.Error())
    }
    loggers.unsubscribe = log.New(f, "[UNSUBSCRIBE]", log.Ldate|log.Ltime|log.Lmicroseconds)
    loggers.unsubscribe.Println("Unsubscribe log started")
	// abuse log
	f, err = os.OpenFile("logs/abuse.log", os.O_RDWR|os.O_CREATE|os.O_APPEND, 0666)
    if err != nil {
        fmt.Println("abuse log file not created", err.Error())
    }
    loggers.abuse = log.New(f, "[ABUSE]", log.Ldate|log.Ltime|log.Lmicroseconds)
    loggers.abuse.Println("Abuse log started")
	// unknown urls log
	f, err = os.OpenFile("logs/unknown_urls.log", os.O_RDWR|os.O_CREATE|os.O_APPEND, 0666)
    if err != nil {
        fmt.Println("unknown url log file not created", err.Error())
    }
    loggers.url = log.New(f, "[URLS]",0)
	loggers.url.SetPrefix(fmt.Sprintf("URLS->[%s] ",time.Now().Format("2006-01-02 - 15:04:05")))
    loggers.url.Println("Unknown urls log started")
}

func GetTrackDomainFromHeader(ctx *fasthttp.RequestCtx) string {
	RemoteIP := ctx.RemoteAddr().String()
	if strings.Contains(RemoteIP,"127.0.0.1") {
		return string(ctx.Request.Header.Peek("X-Host")[:])
	} else {
		return string(ctx.Request.Header.Peek("Host")[:])
	}
}

// 2022.10.24 tested and working but not tested with cloudlare proxy and amazon cloudfront only plain nginx proxy
func GetIpAddressFromHeader(ctx *fasthttp.RequestCtx) string {
	RemoteIP := ctx.RemoteAddr().String()
	if strings.Contains(RemoteIP,":") {
		host, _, err := net.SplitHostPort(RemoteIP)
		if err == nil {
		RemoteIP = host
		}
	}
	CfConnectingip := string(ctx.Request.Header.Peek("Cf-Connecting-Ip")[:])

	// we pass this header from proxy to make sure it's our proxy
    Internal := ctx.Request.Header.Peek("Internal")[:]
	if string(Internal) != "" {
         RemoteIP = string(Internal)
	}
    // i though it may work but it doest the upper lines are correct but untested with amazon cloudfront, it may not work
	// if strings.Contains(RemoteIP,"127.0.0.1") {
	// 	RemoteIP = string(ctx.Request.Header.Peek("X-Forwarded-For")[:])
	// }

	if CfConnectingip != "" {
		RemoteIP = CfConnectingip
	}
	return RemoteIP
}

func LoadDeps() {
	var err error
	geodb, err = geoip2.Open(ProgramPath+"/GeoLite2-City.mmdb")
	if err != nil {
		log.Printf("Unable to load geo database: %s\n",err)
	}
	//defer geodb.Close()
}

func ReturnLocation(ip string) string {
	returnstr := ""
	// If you are using strings that may be invalid, check that ip is not nil
	ipas := net.ParseIP(ip)
	record, err := geodb.City(ipas)
	if err == nil {
		country := record.Country.Names["en"]
		city := record.City.Names["en"]
		if country != "" {
			returnstr = country
		}
		if city != "" {
			returnstr = returnstr + " / " + city
		}
	}
	return returnstr
}

func HashIt(text string) string {
	//algorithm := map[string]string{"1":"s", "2":"m", "3":"z", "4":"c", "5":"k", "6":"e", "7":"r", "8":"l", "9":"o", "0":"p", "u":"a"}
	if DEBUG > 0 {
			Logas("Hashing text: %s", text)
	}
	output := ""
	for _, char := range text {
			for echar := range Algorithm {
					if echar == string(char) {
							output = output + Algorithm[echar]
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
	output := ""
	for _, char := range encoded {
			for echar := range Algorithm {
					if Algorithm[echar] == string(char) {
							output = output + echar
							break
					}
			}
	}
	return output
}

func ContainsI(a string, b string) bool {
    return strings.Contains(
        strings.ToLower(a),
        strings.ToLower(b),
    )
}