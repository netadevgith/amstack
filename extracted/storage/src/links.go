package main

import (
	"bytes"
	"crypto/md5"
	"encoding/hex"
	"encoding/json"
	"fmt"
	router2 "github.com/fasthttp/router"
	_ "github.com/fasthttp/router"
	"github.com/oschwald/geoip2-golang"
	"io/ioutil"
	"net"
	"net/http"
	"strings"

	//routing "github.com/qiangxue/fasthttp-routing"
	"github.com/valyala/fasthttp"
	"io"
	"log"
	"math/rand"
	"os"
	"time"
)


func ReturnMD5HashOfFile(filePath string) (string, error) {
	var returnMD5String string
	file, err := os.Open(filePath)
	if err != nil {
		return returnMD5String, err
	}
	defer file.Close()
	hash := md5.New()
	if _, err := io.Copy(hash, file); err != nil {
		return returnMD5String, err
	}
	hashInBytes := hash.Sum(nil)[:16]
	returnMD5String = hex.EncodeToString(hashInBytes)
	return returnMD5String, nil
}

func IfImageHashExists(hash string) bool {
	if IfRedisHeExists("image_links",hash) {
		return true
	}
	return false
}

type ImageHashItem struct {
	Hash     string `json:"hash"`
	Filename string `json:"filename"`
}

func ReturnImageItem(hash string, filename string) ImageHashItem {
	obj := ImageHashItem {}
	obj.Hash = hash
	if filename == "" {
	obj.Filename = RedisHValReturn("image_links",hash)
	} else {
		obj.Filename = filename
	}
	return obj
}

type CampaignLinks struct {
	Id int64 `json:"id"`
	Uid string `json:"uid"`
	Customer_id string `json:"customer_id"`
	Type string `json:"type"`
	Name string `json:"name"`
	Subject string `json:"subject"`
	Html string `json:"html"`
	From_email string `json:"from_email"`
	From_name string `json:"from_name"`
	Reply_to string `json:"reply_to"`
	Status string `json:"status"`
	Trackurl string `json:"trackurl"`
	Tracktype string `json:"tracktype"`
	Auto_pause string `json:"auto_pause"`
	Run_at string `json:"run_at"`
	Delivery_at string `json:"delivery_at"`
	Created_at string `json:"created_at"`
	Updated_at string `json:"upadted_at"`
	Template_source string `json:"template_source"`
	Last_error string `json:"last_error"`
	Mail_list_id int64 `json:"mail_list_id"`
	Mail_list_uid string `json:"mail_list_uid"`
	Mail_list_name string `json:"mail_list_name"`
	Deployment string `json:"deployment"`
	Urls []string `json:"urls"`
	Images []string `json:"images"`
	Urlsnumeric []struct {
		Id int `json:"id"`
		Url string `json:"url"`
	} `json:"urlsnumeric"`
    Imagesnumeric []struct {
    	Id int `json:"id"`
    	Filename string `json:"filename"`
	} `json:"imagesnumeric"`
}



type TrackingLink struct {
	Campaign_uid string `json:"campaign_uid"`
	Link_type int64 `json:"link_type"` // { open => 0, link => 1, unsubscribe => 2, image => 3 }
	Redirect_id int `json:"redirect_id"` // link id in campaigns
	Redirect_type int `json:"redirect_type"` // { 301 => 1, js => 2 }
	Test int `json:"test"` // 0 , 1 (Testas)
	Email string `json:"email"`
	Message_id string `json:"message_id"`
	Subscriber_id int `json:"subscriber_id"`
	Server string `json:"server"`
}

func GetUrlForId(campaign_uid string, id int) string {
	if IfRedisHeExists("campaigns_links",campaign_uid) {
		redata := RedisHValReturn("campaigns_links",campaign_uid)
		Tracking := CampaignLinks{}
		err := json.Unmarshal([]byte(redata), &Tracking)
		if err != nil {
			log.Printf("Got problems in umarshalling the campaign_links item: %s\n",campaign_uid)
		}
		for _, value := range Tracking.Urlsnumeric {
			//log.Printf("Got numeric: %d url: %s\n",value.Id,value.Url)
			if value.Id == id {
				return value.Url
			}

		}
	}
	return ""
}

func SubmitLinksCampaign(camp CampaignLinks) bool {
	jsonas, err := json.Marshal(camp)
	if err != nil {
		log.Printf("Unable to marshal campaign in function SubmitLinksCampaign\n")
		return false
	}
	if RedisHsetSingle("campaigns_links",camp.Uid,string(jsonas)) {
		return true
	}
	log.Printf("Total fail in function SubmitLinksCampaign\n")
	return false
}


func RandomInteger(min, max int) int {
	rand.NewSource(time.Now().UnixNano())
	return rand.Intn(max-min) + min
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

// We should improve this functions by adding link obfuscation techniques
func CreateTrackingLink(Tracking string) string {
	var identification string = randomDateInteger()
	for {
		if !IfRedisHeExists("links",identification) {
		break
		}
		identification = randomDateInteger()
		//fmt.Printf("Got new identification: %s\n", identification)
	}
	if RedisHsetSingle("links",identification,Tracking) {
		return identification
	}
	return ""
}

func GetTrackDomainFromHeader(ctx *fasthttp.RequestCtx) string {
	RemoteIP := ctx.RemoteAddr().String()
	if strings.Contains(RemoteIP,"127.0.0.1") {
		return string(ctx.Request.Header.Peek("X-Host")[:])
	} else {
		return string(ctx.Request.Header.Peek("Host")[:])
	}
}

func GetIpAddressFromHeader(ctx *fasthttp.RequestCtx) string {
	RemoteIP := ctx.RemoteAddr().String()
	CfConnectingip := string(ctx.Request.Header.Peek("Cf-Connecting-Ip")[:])

	if strings.Contains(RemoteIP,"127.0.0.1") {
		RemoteIP = string(ctx.Request.Header.Peek("X-Forwarded-For")[:])
	}

	if CfConnectingip != "" {
		RemoteIP = CfConnectingip
	}

	// debug headers
	//headers := ctx.Request.Header.String()
	//log.Printf("Headers: %s\n",headers)
	//log.Printf("IP Address triangulated to: %s\n",RemoteIP)
	return RemoteIP
}

type TrackingSubmission struct {
	Campaign_uid string `json:"campaign_uid"`
	Link_type int64 `json:"link_type"` // { open => 0, link => 1, unsubscribe => 2, image => 3 }
	Redirect_id int `json:"redirect_id"` // link id in campaigns
	Redirect_type int `json:"redirect_type"` // { 301 => 1, js => 2 }
	Test int `json:"test"` // 0 , 1 (Testas)
	Email string `json:"email"`
	Message_id string `json:"message_id"`
	Subscriber_id int `json:"subscriber_id"`
	Server string `json:"server"`
	Deployment string `json:"deployment"`
	Campaign_Id int64 `json:"campaign_id"`
	Customer_id string `json:"customer_id"`
	CampaignName string `json:"campaign_name"`
	CampaignSubject string `json:"campaign_subject"`
	Url string `json:"url"`
	Mail_list_id int64 `json:"mail_list_id"`
	Mail_list_uid string `json:"mail_list_uid"`
	Mail_list_name string `json:"mail_list_name"`
	Ip string `json:"ip"`
	Trackdomain string `json:"trackdomain"`
	UserAgent string `json:"useragent"`
	Location string `json:"location"`
	Just_key string `json:"just_key"`
}

func DoHttpTransfer(submission TrackingSubmission, timeout int) bool {
	url := submission.Deployment+"getexternaltracking"
	//timeout := 10 // maximum of 10 secs
	jsonas, err := json.Marshal(submission)
	if err != nil {
		fmt.Printf("Unable to marshall the json for remote tracking submission: %s\n",err)
		return false
	}
	ApiClient := http.Client{
		Timeout: time.Second * time.Duration(timeout),
	}
	req, err := http.NewRequest(http.MethodPost, url, bytes.NewBuffer(jsonas)) // EDIT
	if err != nil {
		return false
	}
	res, getErr := ApiClient.Do(req)
	if getErr != nil {
		return false
	}
	if settings.Debug {
		log.Printf("I've got respond from deployment api: %s\n", res.Status)
	}
	if res.StatusCode > 0 && res.StatusCode < 404 {
		if res.StatusCode == 200 {
			return true
		}
	}

	return false
}

// submits any type of tracking to the needed deployment
func TransferTracking(uniqid string,Track TrackingLink, remotas RemoteDetails) {
	//log.Printf("Transfertracking is in action!\n")
	if uniqid != "" {
		redata := RedisHValReturn("campaigns_links", Track.Campaign_uid)
		campaign := CampaignLinks{}
		err := json.Unmarshal([]byte(redata),&campaign)
		if err != nil {
			// we should delete that corrupted data...
			return
		}
		// ok we got the valid data, so we should pass it to the remote deployment but first we should get that deployment api url
		deployment := GetApplianceApiURL(campaign.Deployment)
		if deployment != "" {
			// post to api
			Submission := TrackingSubmission{Campaign_uid: campaign.Uid, Link_type: Track.Link_type, Redirect_id: Track.Redirect_id,
				Redirect_type: Track.Redirect_type, Test: Track.Test, Email: Track.Email, Message_id: Track.Message_id,Subscriber_id: Track.Subscriber_id,
				Server: Track.Server, Deployment: deployment, Campaign_Id: campaign.Id, Customer_id: campaign.Customer_id, CampaignName: campaign.Name, CampaignSubject: campaign.Subject,
				Mail_list_id: campaign.Mail_list_id, Mail_list_uid: campaign.Mail_list_uid, Mail_list_name: campaign.Mail_list_name, Just_key: "1122", Ip: remotas.RemoteIP, Trackdomain: remotas.TrackDomain, UserAgent: remotas.UserAgent, Location: remotas.Location}
			Submission.Url = GetUrlForId(campaign.Uid,Track.Redirect_id)
			if DoHttpTransfer(Submission,10) == false {
				// we were unable to transfer data
				// set the tracking_queue
				jousonas, err := json.Marshal(Submission)
				if err != nil {
					fmt.Printf("Unable to marshall the json for remote tracking submission13: %s\n",err)
					return
				}
                RedisHsetSingle("tracking_queue",uniqid,string(jousonas))
				if settings.Debug {
					log.Printf("http post to deployment was bad!\n")
				}
			} else {
				// everything is ok
				// delete the tracking queue if exists
				RedisHDelSingle("tracking_queue",uniqid)
				if settings.Debug {
					log.Printf("http post to deployment was ok!\n")
				}
			}
		}

	}
	return
}

type RemoteDetails struct {
	RemoteIP string `json:"remoteip"`
	UserAgent string `json:"useragent"`
	TrackDomain string `json:"trackdomain"`
	Location string `json:"location"`
}

func ReturnLocation(ip string) string {
	db, err := geoip2.Open("./GeoLite2-City.mmdb")
	if err != nil {
		return ""
	}
	defer db.Close()
	returnstr := ""
	// If you are using strings that may be invalid, check that ip is not nil
	ipas := net.ParseIP(ip)
	record, err := db.City(ipas)
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

func CustomHttpRequest(ctx *fasthttp.RequestCtx) {
	//fmt.Fprintf(ctx, "Hello, %s!\n", ctx.UserValue("name"))
	// print remote ip and request to the log
	location := fmt.Sprint(ctx.UserValue("pass"))
	remoteip := GetIpAddressFromHeader(ctx)
	trackdom := GetTrackDomainFromHeader(ctx)
	lokacija := ReturnLocation(remoteip)
	//log.Printf("Got location: %s\n",lokacija)
	remotas := RemoteDetails{RemoteIP: remoteip, UserAgent: string(ctx.UserAgent()), TrackDomain: trackdom,Location: lokacija}
    if settings.Debug {
		log.Printf("Links web request: %s from: %s", location, remoteip)
	}
	if location == "" {
		// just empty root
		//fmt.Fprintf(ctx,"empty\n")
		return
	}
	if IfRedisHeExists("links",location) {
    redata := RedisHValReturn("links",location)
    Tracking := TrackingLink{}
	err := json.Unmarshal([]byte(redata), &Tracking)
	if err != nil {
		log.Printf("We had problems in unmarshaling the redis hkey for url: %s",location)
		return
	}
		// { open => 0, link => 1, unsubscribe => 2, image => 3 }
	switch Tracking.Link_type {
	// open tracking
	case 0:
		if settings.Debug {
			log.Printf("CLASSIFICATION: We got open tracking url")
		}
		go func() {
			TransferTracking(location, Tracking, remotas)
			// add to local opener queue FIXME i don't know if all of that shit is actually needed
			mxas := RetrieveMX(ReturnDomainFromEmail(Tracking.Email))
			revdata := RedisHValReturn("campaigns_links", Tracking.Campaign_uid)
			campaign := CampaignLinks{}
			openeris := Opener{Email: Tracking.Email, Ip_address: remoteip, Server_ip: Tracking.Server, User_agent: remotas.UserAgent,
				Location: lokacija, Mx: mxas, Domain: remotas.TrackDomain}
			err := json.Unmarshal([]byte(revdata), &campaign)
			if err == nil {
				openeris.Deployment = campaign.Deployment
				openeris.Campaign = campaign.Name
				openeris.Maillist = campaign.Mail_list_name
			}
			jsonas, err := json.Marshal(openeris)
			if err != nil {
				return
			}
			table, queue_table := ReturnBlTableByTypeID(OPENERS_TYPE)
			if IfRedisHeExists(table, Tracking.Email) == false {
				RedisIncrProviderBy(ReturnRDTable(CACHELIST_OPENERS_COUNT), Tracking.Email, 1)
			}
			RedisHsetSingle(table, Tracking.Email, string(jsonas))
			RedisHsetSingle(queue_table, Tracking.Email, string(jsonas))
		}()
		return
	// link click
	case 1:
		if settings.Debug {
			log.Printf("CLASSIFICATION: We got link url")
		}
		urlas := GetUrlForId(Tracking.Campaign_uid,Tracking.Redirect_id)
		if urlas != "" {
			log.Printf("Redirecting to: %s\n",urlas)
			ctx.Redirect(urlas,fasthttp.StatusMovedPermanently)
			go func() {
			TransferTracking(location,Tracking,remotas)
			// add to local click queue FIXME i don't know if all of that shit is actually needed
			mxas := RetrieveMX(ReturnDomainFromEmail(Tracking.Email))
			revdata := RedisHValReturn("campaigns_links", Tracking.Campaign_uid)
			campaign := CampaignLinks{}
			clickeris := Clicker{Email: Tracking.Email, Ip_address: remoteip, Server_ip: Tracking.Server, User_agent: remotas.UserAgent,
				Location: lokacija, Mx: mxas, Domain: remotas.TrackDomain }
			err := json.Unmarshal([]byte(revdata),&campaign)
			if err == nil {
				clickeris.Deployment = campaign.Deployment
				clickeris.Campaign = campaign.Name
				clickeris.Maillist = campaign.Mail_list_name
			}
			jsonas, err := json.Marshal(clickeris)
			if err != nil {
				return
			}
			table, queue_table := ReturnBlTableByTypeID(CLICKERS_TYPE)
			if IfRedisHeExists(table, Tracking.Email) == false {
				RedisIncrProviderBy(ReturnRDTable(CACHELIST_CLICKERS_COUNT), Tracking.Email, 1)
			}
			RedisHsetSingle(table,Tracking.Email,string(jsonas))
			RedisHsetSingle(queue_table,Tracking.Email,string(jsonas))
			}()
			//ctx.Response.Header.SetCanonical([]byte(urlas),nil)
			//ctx.Response.SetStatusCode(fasthttp.StatusMovedPermanently)
		}
		return
	// unsubscribe
	case 2:
		if settings.Debug {
			log.Printf("CLASSIFICATION: We got unsubscribe link url")
		}
		content, err := ioutil.ReadFile("./forms/unsubscribe.html")
		if err != nil {
			log.Fatal(err)
		}
		ctx.SetContentType("text/html")
		ctx.SetBody(content)
		ctx.SetStatusCode(fasthttp.StatusOK)
		go TransferTracking(location,Tracking,remotas)
		return
	// image
	case 3:
		log.Printf("CLASSIFICATION: We got image link url and this section is not implemented yet!")
		return
	}

	}
	if !strings.Contains(location,"/") && !strings.Contains(location,"..") {
		_, err := os.Stat("./uploads/"+location)
		if os.IsNotExist(err) {
			return
		} else {
			if settings.Debug {
				log.Printf("Found image on local uploads directory and serving it!\n")
			}
            ctx.SendFile("./uploads/"+location)
			return
		}
	}

	//if ctx.UserValue("pass") == "labas" {
	//	fmt.Fprintf(ctx,"Labas, Viskas ok?\n")
	//}
	//if ctx.UserValue("pass") == "viso" {
	//	fmt.Fprintf(ctx,"Viso gero! :D\n")
	//}
}

func Index(ctx *fasthttp.RequestCtx) {
	//ctx.WriteString("")
	ctx.Redirect("/report",fasthttp.StatusMovedPermanently)
}

//func UnsubscribeBeta(ctx *fasthttp.RequestCtx) {
//	content, err := ioutil.ReadFile("./forms/unsubscribe.html")
//	if err != nil {
//		log.Fatal(err)
//	}
//	ctx.SetContentType("text/html")
//	ctx.SetBody(content)
//	ctx.SetStatusCode(fasthttp.StatusOK)
//}

func ReceiveAbuseBeta(ctx *fasthttp.RequestCtx) {
	method := ctx.Method()
	if string(method) == "POST" {
		log.Printf("We got abuse submission")
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
			jsonas, err := json.Marshal(SQLBlacklistItem{Val: email, Type: BLACKLIST_EMAILS_TYPE_ABUSE_REPORT, Reason: abuseform})
			if err == nil {
				table, queue_table := ReturnBlTableByTypeID(BLACKLIST_EMAILS_TYPE_ABUSE_REPORT)
				if IfRedisHeExists(table, email) == false {
					RedisHsetSingle(queue_table, email, string(jsonas))
					RedisHsetSingle(table, email, fmt.Sprintf("%d", BLACKLIST_EMAILS_TYPE_ABUSE_REPORT))
					RedisIncrProviderBy(ReturnCacheTableByOrigins(BLACKLIST_EMAILS_TYPE_ABUSE_REPORT), email, 1)
				} else {
					log.Printf("Reported email: %s from abuse form, already exists on the system\n",email)
				}
			} else {
				log.Printf("Got error in abuse form then serializing posted material to json: \n",err)
			}
		} else {
			log.Printf("Got error in abuse form submission maybe required email field is empty\n")
		}
		log.Printf("Got abuse form submitted: %s\n",abuseform)
		ctx.SetContentType("text/json")
		ctx.SetBody([]byte(`{ "result": "success" }`))
		ctx.SetStatusCode(fasthttp.StatusOK)
	}
	return
}

func ReportBeta(ctx *fasthttp.RequestCtx) {
	//ctx.WriteString("")
	//method := ctx.Method()
	//erroras := ""
	//if string(method) == "POST" {
	//	log.Printf("We got post method from reportbeta")
	//	name := ctx.FormValue("name")
	//	if string(name) == "" {
	//		erroras = "Name is empty!"
	//	}
	//	log.Printf("Got name: %s from submit form\n",name)
	//}
	content, err := ioutil.ReadFile("./forms/abuse.html")
	if err != nil {
		log.Fatal(err)
	}
	//content = []byte(strings.Replace(string(content), "{ERROR}", erroras, -1))
	ctx.SetContentType("text/html")
	ctx.SetBody(content)
	ctx.SetStatusCode(fasthttp.StatusOK)
}

//func Report(ctx *fasthttp.RequestCtx) {
//	ctx.WriteString("")
//}

func TrackingQueuePool() {
	for {
			table_queue := ReturnRDTable("tracking_queue")
			Size, err := redisdb.HLen(table_queue).Result()
			if err == nil {
				// FIXME get this on
				//if Size > settings.QueueOversizeLimit {
				if Size > 100 {
					if settings.Debug {
						fmt.Printf("The table: %s is over limits: %d, we need to try transfer tracking log to its destination...\n", table_queue, settings.QueueOversizeLimit)
					}
					all_items, err := redisdb.HGetAll(table_queue).Result()
					if err == nil {
						for key, val := range all_items {
							submisija := TrackingSubmission{}
							err := json.Unmarshal([]byte(val),&submisija)
							if err == nil {
								if DoHttpTransfer(submisija,30) {
									RedisHDelSingle("tracking_queue",key)
								}
							}
						}
					} else {
						log.Printf("Fatal error: Unable to get all items from redis table: %s\n",table_queue)
					}
				}
			}

		time.Sleep(time.Second * 30) // every 30 sec
	}
}


func LinksPool() {
	router := router2.New()
	router.GET("/", Index)
	router.GET("/report",ReportBeta)
	//router.GET("/reportbeta",ReportBeta)
	router.POST("/api/v2/reportbeta",ReceiveAbuseBeta)
	router.GET("/{pass}",CustomHttpRequest)

	fmt.Printf("Binding links handler web service to %s:%s...\n", settings.LinksBind, settings.LinksPort)
	s := &fasthttp.Server{
		Handler:            router.Handler,
		Name:               "nginx/1.10.3", // we will use some fake identity
		MaxRequestBodySize: 32 << 20,
	}
	s.ListenAndServe(fmt.Sprintf("%s:%s", settings.LinksBind, settings.LinksPort))
}