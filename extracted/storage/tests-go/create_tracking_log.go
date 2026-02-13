package main

import (
        "bytes"
//        "crypto/tls"
//        "database/sql"
//        "encoding/base64"
        "encoding/json"
//        "flag"
        "fmt"
//        "html/template"
//        "io/ioutil"
//        "log"
//        "math/rand"
//        "net"
        "net/http"
//        "net/mail"
//        "net/smtp"
        "os"
//        "path/filepath"
//        "regexp"
//        "strconv"
//        "strings"
//        "sync"
        "time"
)

var (
DEBUG = 1
)

func PostTrackingv3(Tracking TrackingLink) string {
     timeout := 10 // maximum of 10 secs
        jsonas, err := json.Marshal(Tracking)
        if err != nil {
            fmt.Printf("Unable to marshall the json for remote storage tracking log: %s\n",err)
            os.Exit(1)
        }
        ApiClient := http.Client{
                Timeout: time.Second * time.Duration(timeout),
        }
        req, err := http.NewRequest(http.MethodPost, "http://localhost:8082/api/v1/links/postlink", bytes.NewBuffer(jsonas)) // EDIT
        if err != nil {
                fmt.Printf("PostTrackingv3 err1: %s\n", err)
                os.Exit(1)
        }
        req.Header.Set("Authorization", "1122") // EDIT
        res, getErr := ApiClient.Do(req)
        if getErr != nil {
                // TODO implement here a reporting to campaign->error
                fmt.Printf("PostTrackingv3 Critical error, we are unable to reach storage api http service: %v\n", err)
                //ThreadWaitTimeout()
                os.Exit(1)
        }
        if DEBUG > 0 {
                fmt.Printf("I've got respond: %s\n", res.Status)
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

type TrackingLink struct {
	Campaign_uid string `json:"campaign_uid"`
	Link_type int `json:"link_type"` // { open => 0, link => 1, unsubscribe => 2, image => 3 }
	Redirect_id int `json:"redirect_id"` // link id in campaigns
	Redirect_type int `json:"redirect_type"` // { 301 => 1, js => 2 }
	Test int `json:"test"` // 0 , 1 (Testas)
	Email string `json:"email"`
	Message_id string `json:"message_id"`
	Subscriber_id int `json:"subscriber_id"`
	Server string `json:"server"`
}


func main() {
Tracking := TrackingLink{Campaign_uid: "uidas", Link_type: 1, Redirect_id: 1, Redirect_type: 1, Test: 1, Email: "justinas@res.lt", Message_id: "awieogjawriohgaeorihg", Subscriber_id: 10001, Server: "127.0.0.1"}
gotid := PostTrackingv3(Tracking)
if gotid != "" {
fmt.Printf("We succeedded! Returned id is: %s\n",gotid)
}else {
fmt.Printf("Some kind of error accoured\n")
}
}
