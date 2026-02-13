/*
Deferred email handling plugin for gosender

*/


package main

import (
   "fmt"
   "sync"
 //  "github.com/go-redis/redis"
   "encoding/json"
   "time"
)

func DeferredHandlingEnabledForCampaign(uid string) bool {
    done, err := redisdb.Exists(uid + "_deferred_setting").Result()
        if err == nil {
                if done == 1 {
                   return true
                }         
				
	}
	return false
}

func ReturnDeferredCount(uid string) int64 {
	count, err := redisdb.SCard("campaign_" + uid + "_deferreds").Result()
	if err == nil {
		return count
	}
	return 0
}

func DeferredIncreaseCounter(uid string) {
	redisdb.Incr(uid + "_deferred_sent").Err()
	return
}



// DeferredSetting struct
type DeferredSetting struct {
	Enabled                int64 `json:",string"`
	Wait               int64 `json:",string"`

}


func GetDeferredWaitTime(uid string) int64 {
	rawstr, err := redisdb.Get(uid + "_deferred_setting").Result()
	if err == nil { 
		var setting DeferredSetting
		err = json.Unmarshal([]byte(rawstr), &setting)
		if err == nil {
			return setting.Wait
		}
		
	}
	return 0
}

func GetRandomDeferredSubscriber(uid string, wg *sync.WaitGroup) (Subscriber, error) {
	t0 := time.Now()
	var sub Subscriber
	var condition string
	condition = "campaign_" + uid + "_deferreds"
    val, err := redisdb.SPop(condition).Result()
		if err != nil {
			return sub,err
		}
		if err == nil {
			err = json.Unmarshal([]byte(val), &sub)
			if err != nil {
				fmt.Printf("FIXME: subscriber json parse error uid: %s\n", uid)
				Logas("FIXME: subscriber json parse error uid: %s\n", uid)
				return sub,err
    		}
			t2 := time.Now()
			if BENCHMARK > 0 {
				fmt.Printf("Get randomsubscriber took: %s\n", t2.Sub(t0))
			}
			return sub, nil
		}
	
	return sub, err
}





