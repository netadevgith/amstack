package main

import (
	"encoding/json"
	"fmt"
	"io/ioutil"
	"log"
	"os"
	"path/filepath"
)

// Settings - Structure for the program settings
type Settings struct {
	Bind                       string
	Port                       string
	LinksBind                  string
	LinksPort                  string
	Debug                      bool
	Logging                    bool
	RedisPrefix                string
	RedisTable                 string
	ApiAuthorization           string
	SqlHost                    string
	SqlPort                    int
	SqlDba                     string
	SqlUser                    string
	SqlPass                    string
	RedisBlacklistsMXTable     string
	RedisBlacklistsDomainTable string
	RedisBlacklistsNameTable   string
	RedisBlacklistsEmailsTable string
	RedisOpenersTable          string
	RedisClickersTable         string
	RedisDeliveryTable         string
	SQLBlacklistsTable         string
	SQLBlacklistsDomainTable   string
	SQLBlacklistsNameTable     string
	SQLBlacklistsMxTable       string
	SQLOpenersTable            string
	SQLClickersTable           string
	SQLDeliveriesTable         string
	SqlStorageTable            string
	BlQueueWatcherInterval     int
	QueueOversizeLimit         int64
}

// LoadSettings - Loads the program settings data from json file
func LoadSettings() {
	settingsfile := fmt.Sprintf("%s.json", filepath.Base(os.Args[0]))
	Pathas, err := filepath.Abs(filepath.Dir(os.Args[0]))
	if err != nil {
		ProgramPanic(err)
	}
	ProgramPath = Pathas
	// check if settings file exists
	if _, err := os.Stat(ProgramPath + "/" + settingsfile); os.IsNotExist(err) {
		// path/to/whatever does not exist
		fmt.Printf("Settings file was not found, exiting... %s\n", err)
		os.Exit(1)
	}

	jsonFile, err := os.Open(ProgramPath + "/" + settingsfile)
	// if we os.Open returns an error then handle it
	if err != nil {
		ProgramPanic(err)
	}
	defer jsonFile.Close()

	byteValue, err := ioutil.ReadAll(jsonFile)
	if err != nil {
		fmt.Println(err)
	}
	err = json.Unmarshal(byteValue, &settings)
	if err != nil {
		fmt.Printf("Error loading settings file, json structure must be corrupted somehow %s\n", err)
		os.Exit(1)
	}
	if settings.Logging == true {
		var logpath = fmt.Sprintf("%s/%s.log", Pathas, filepath.Base(os.Args[0]))
		var file, err1 = os.OpenFile(logpath, os.O_RDWR|os.O_CREATE|os.O_APPEND, 0666)
		if err1 != nil {
			ProgramPanic(err1)
		}
		Log = log.New(file, "", log.LstdFlags|log.Lshortfile)
		if settings.Logging {
			Logas("LogFile: " + logpath)
		}
	}
	//	if settings.Debug {
	fmt.Printf("Settings Loaded: %+v\n", settings)
	//	}
	return
}
