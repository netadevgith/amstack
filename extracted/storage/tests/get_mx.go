package main

import (
	"fmt"
	"net/http"
	"time"
        "os"
)


func main() {
EMAIL := os.Args[1]
if get_bl(EMAIL) {
fmt.Printf("The MX: %s exist on the remote blacklists storage engine\n",EMAIL)
} else {
fmt.Printf("The MX: %s does not exist on the remote blacklists storage engine\n",EMAIL)
}
}

func get_bl(record string) bool {
url := "http://116.202.114.76:8081/api/v1/blacklists/mxcheck/"+record
api_key := "Ab0Zb1zB04mP"
timeout := 2 // maximum of 2 secs
	ApiClient := http.Client{ 
		Timeout: time.Second * time.Duration(timeout),
	}
req, err := http.NewRequest(http.MethodGet, url, nil)
	if err != nil {
		fmt.Printf("err1: %s\n",err)
	}
req.Header.Set("Authorization", api_key)
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
