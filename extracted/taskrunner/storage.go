package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"time"

	"github.com/jmoiron/sqlx"
)


type UniversalStorageTracking struct {
	Email      string `json:"email"`
	Ip_address string `json:"ip_address"`
	Server_ip  string `json:"server_ip"`
	Server_ptr string `json:"server_ptr"`
	User_agent string `json:"user_agent"`
	Location   string `json:"location"`
	Date_added string `json:"date_added"`
	Mx         string `json:"mx"`
	Deployment string `json:"deployment"`
	Domain     string `json:"domain"`
	Campaign   string `json:"campaign"`
	Maillist   string `json:"maillist"`
}

// type Clicker struct {
// 	Email      string `json:"email"`
// 	Ip_address string `json:"ip_address"`
// 	Server_ip  string `json:"server_ip"`
// 	Server_ptr string `json:"server_ptr"`
// 	User_agent string `json:"user_agent"`
// 	Location   string `json:"location"`
// 	Date_added string `json:"date_added"`
// 	Mx         string `json:"mx"`
// 	Deployment string `json:"deployment"`
// 	Domain     string `json:"domain"`
// 	Campaign   string `json:"campaign"`
// 	Maillist   string `json:"maillist"`
// }

// 0 - opener, 1 - clicker
// inserts tracking data into the openers table in the tracking database
func SubmitTrackingas(tipas int, obj UniversalStorageTracking) bool {
	db, err := sqlx.Connect("mysql", fmt.Sprintf("%s:%s@tcp(%s:%d)/%s", TRACKINGAS_USER, TRACKINGAS_PASS, TRACKINGAS_HOST, 3306, TRACKINGAS_DB))
		if err != nil {
			loggers.debug.Printf("Got error then tried to contact to trackingas mysql server %s\n", err)
			return false
		}
		defer db.Close()
	query := "INSERT INTO openers (email,ip_address,server_ip,server_ptr,user_agent,location,`when`,deployment,campaign,maillist)"
	params := fmt.Sprintf("%s','%s','%s','%s','%s','%s','%s','%s','%s','%s",obj.Email,obj.Ip_address,obj.Server_ip,obj.Server_ptr,obj.User_agent,obj.Location,obj.Date_added,obj.Deployment,obj.Campaign,obj.Maillist)
	sql := fmt.Sprintf("%s VALUES('%s')",query,params)
	//log.Printf("Doing sql: %s\n",sql)
	_, err = db.Exec(sql)
	if err == nil {
		return true
	} else {
		loggers.debug.Printf("SQL ERROR: %s\n",err)
	}
	return false
}

// 0 - opener, 1 - clicker 
// clicker is untested but should work fine
func SubmitToStorage(tipas int, obj UniversalStorageTracking) bool {
	timeout := 10
	var url string
	// make array from the object
	objects := []UniversalStorageTracking{obj}
	switch tipas {
	case 0: // opener
	url = fmt.Sprintf("%s/api/v1/openers/submit",STORAGE_API)
	case 1: // clicker
	url = fmt.Sprintf("%s/api/v1/clickers/submit",STORAGE_API)
	}
	 jsonas, err := json.Marshal(objects)
			if err != nil {
					fmt.Printf("Unable to marshall the json for remote tracking submission: %s\n",err)
					return false
			}
			ApiClient := http.Client{
					Timeout: time.Second * time.Duration(timeout),
			}
			req, err := http.NewRequest(http.MethodPost, url, bytes.NewBuffer(jsonas)) // EDIT
			if err != nil {
					fmt.Printf("Unable to make a request to storage api %s\n",err)
					return false
			}
			req.Header.Set("Authorization", STORAGE_KEY) // EDIT
			res, getErr := ApiClient.Do(req)
			if getErr != nil {
					fmt.Printf("CRITICAL ERROR: When posting to storage api %s\n",getErr)
					return false
			}
			if res.StatusCode > 0 && res.StatusCode < 404 {
					if res.StatusCode == 200 {
							return true
					}
			}
			return false
	
	}
	
	
// func StorageApiTrackingAdd(type string) bool {
// 	timeout := 10
// 	url := fmt.Sprintf("%s/api/v1/%s/submit",STORAGE_API,type)
// 	emails := []string{email}
// 	 jsonas, err := json.Marshal(emails)
// 			if err != nil {
// 					fmt.Printf("Unable to marshall the json for remote tracking submission: %s\n",err)
// 					return false
// 			}
// 			ApiClient := http.Client{
// 					Timeout: time.Second * time.Duration(timeout),
// 			}
// 			req, err := http.NewRequest(http.MethodPost, url, bytes.NewBuffer(jsonas)) // EDIT
// 			if err != nil {
// 					fmt.Printf("Unable to make a request to storage api %s\n",err)
// 					return false
// 			}
// 			req.Header.Set("Authorization", STORAGE_KEY) // EDIT
// 			res, getErr := ApiClient.Do(req)
// 			if getErr != nil {
// 					fmt.Printf("some other error then posting to storage api %s\n",getErr)
// 					return false
// 			}
// 			if res.StatusCode > 0 && res.StatusCode < 404 {
// 					if res.StatusCode == 200 {
// 							return true
// 					}
// 			}
// 			return false
// }	

func StorageApiBlacklistAdd(email string, tipas int) bool {
	timeout := 10
	url := fmt.Sprintf("%s/api/v1/blacklists/set/%d",STORAGE_API,tipas)
	emails := []string{email}
	 jsonas, err := json.Marshal(emails)
			if err != nil {
					fmt.Printf("Unable to marshall the json for remote tracking submission: %s\n",err)
					return false
			}
			ApiClient := http.Client{
					Timeout: time.Second * time.Duration(timeout),
			}
			req, err := http.NewRequest(http.MethodPost, url, bytes.NewBuffer(jsonas)) // EDIT
			if err != nil {
					fmt.Printf("Unable to make a request to storage api %s\n",err)
					return false
			}
			req.Header.Set("Authorization", STORAGE_KEY) // EDIT
			res, getErr := ApiClient.Do(req)
			if getErr != nil {
					fmt.Printf("some other error then posting to storage api %s\n",getErr)
					return false
			}
			if res.StatusCode > 0 && res.StatusCode < 404 {
					if res.StatusCode == 200 {
							return true
					}
			}
			return false
	
	}
	