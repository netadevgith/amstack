package main

import (
        "github.com/go-redis/redis"
        "fmt"
        "os"
        "path/filepath"
        "errors"
)

// smtphost
// CUST_SERVER_TRACKING

var (
        redisdb     *redis.Client
        campaign string
        campLogfile *os.File
        ProgramPath = ""
)


func DetectProgramPath() {
        Pathas, err := filepath.Abs(filepath.Dir(os.Args[0]))
        if err != nil {
                ProgramPath = ""
        }
        ProgramPath = Pathas
}

func init() {
	DetectProgramPath()
}


// code here
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


func main() {
campaign = "61a337dd513e5"
InitializeCampaignLogging()
WriteCampaignLog("ojojojoj");
WriteCampaignLog("ojojojojojbled");
}
