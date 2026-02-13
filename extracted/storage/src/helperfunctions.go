package main

import (
	"fmt"
	"net"
	"strings"
    "unicode"
	"github.com/jmoiron/sqlx"
	"os"
)

// helper functions for storage functionality

func PopulateRedis(sql_table string, redis_table string) {
	dbconn()
	sql_var1 := fmt.Sprintf("SELECT val,type FROM %s", sql_table)
	q, args, err := sqlx.In(sql_var1) //creates the query string and arguments
	if err != nil {
		fmt.Printf("I've tried to crush sql with stupid sql query and it did it, it crashed :(... %s\n", err)
	}
	rows, err := mysqldb.Query(q, args...)
	if err != nil {
		fmt.Printf("Crashed again, oh no damn shit with it... %s\n", err)
	}

	for rows.Next() {
		var val string
		var typea int
		if err := rows.Scan(&val, &typea); err != nil {
			fmt.Printf("Failing to set redis data for: %s %s\n", val, typea)
			continue
		}
		// here we should set the appropriate entries in redis
		redisdb.HSet(ReturnRDTable(redis_table), val, typea)
	}
}

// systematic lower strings in redis/keydb for the specified table, it extracts the same values, deletes the old key and creates new in the lowercase with same values
func LowerFixRedis(table string) {
	tbl := ReturnRDTable(table)
	exists, err := redisdb.Exists(tbl).Result()
	if err == nil && exists >0 {
	 all_items, err := redisdb.HGetAll(tbl).Result()
	 if err == nil {
		 fmt.Printf("Fixing table %s\n",tbl)
		for key, val := range all_items {
			   lower_key := LowerString(key)
			   if key != lower_key {
//				   fmt.Printf("We detected that key: %s is not lowercase\n",key)
//		       fmt.Printf("Erasing record %s\n",key)
			   _, _ = redisdb.HDel(tbl,key).Result()
//				if err == nil && res > 0 {
//					fmt.Printf("Record %s have been erased\n",key)
//				}
				_, err2 := redisdb.HSet(tbl,lower_key,val).Result()
                    if err2 != nil {
			          fmt.Printf("Error setting %s with %s error: \n",key,lower_key,err2)
					}
				}
			
		  
		  

		}
	 } else {
		 fmt.Printf("Unable to get elements from redis table %s\n",tbl)
	 }

	} else {
		fmt.Printf("Table %s does not seems to exist\n",tbl)
	}
}

func IsUpper(s string) bool {
    for _, r := range s {
        if !unicode.IsUpper(r) && unicode.IsLetter(r) {
            return false
        }
    }
    return true
}

func ReturnDomainFromEmail(email string) string {
	s := strings.Split(TrimChars(email), "@")
	if isset(s, 1) && s[1] != "" {
		return s[1]
	} else {
		return ""
	}
}

func ValidMailv2(email string) bool {
	if validRfc5322Regexp.MatchString(email) {
		return true
	}
	return false
}

func PopulateCounters(table string, destination_table string, force_mode bool) {
	all_items, err := redisdb.HGetAll(table).Result()
	if err == nil {
		if force_mode {
			_, err := redisdb.Del(destination_table).Result()
			if err == nil {
				fmt.Printf("Sucessfully cleared the redis table %s\n", destination_table)
			}
		}
		fmt.Printf("Populating data counters from table %s to table %s\n", table, destination_table)
		for key, _ := range all_items {
			RedisIncrProviderBy(destination_table, key, 1)
		}
	} else {
		fmt.Printf("Unable to get data from redis table %s err: %s\n", table, err)
	}
}

func isset(arr []string, index int) bool {
	return (len(arr) > index)
}

func RetrieveMX(host string) string {
	mxrecords, _ := net.LookupMX(host)
	var hostsraw string
	count := 0
	for _, mx := range mxrecords {
		count++
		//fmt.Println(mx.Host, mx.Pref)
		if count == 1 {
			hostsraw = mx.Host
		} else {
			hostsraw = hostsraw + "," + mx.Host
		}
	}
	return hostsraw
}

func GetProviderMX(provider string) string {
	if prov, ok := Mxes[provider]; ok {
		return prov
	}
	host := RetrieveMX(provider)
	Mxes[provider] = host
	return host
}

func LowerString(str string) string {
	return strings.ToLower(str)
}

func IfHashExists(hash string) bool {
	if IfRedisHeExists("image_links",hash) {
		return true
	}
	return false
}

func RemoveFile(path string) {
   _, err := os.Stat(path)
   if os.IsNotExist(err) {
	// file does not exist, do something
	fmt.Printf("File: %s does not exist\n",path)
	return
   }
    err = os.Remove(path)
      if err != nil {
        fmt.Printf("Unable to remove file: %s error: %s\n",path,err)
        return
}

}
