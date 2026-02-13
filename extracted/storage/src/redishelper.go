package main

import (
	"fmt"
	"log"
	"time"

	"github.com/go-redis/redis"
)

func RedisInit() {
	redisdb = redis.NewClient(&redis.Options{Addr: "localhost:6379", Password: "", DB: 0, ReadTimeout: time.Hour})
}

// ReturnRDTable Returns table with prefix if it's set, else returns the same table name
func ReturnRDTable(table string) string {
	if settings.RedisPrefix != "" {
		return fmt.Sprintf("%s:%s", settings.RedisPrefix, table)
	}
	return table
}

func IfRedisHeExists(table string, data string) bool {
	exist, err := redisdb.HExists(ReturnRDTable(table), data).Result()
	if err == nil {
                if exist == true {
		return true
                }
	}
	return false
}

func RedisHsetSingle(table string, key string, value string) bool {
	_, err := redisdb.HSet(ReturnRDTable(table),key,value).Result()
	if err != nil {
		log.Printf("We got problem setting redis single entry in %s\n",table)
		return false
	}
	return true
}

func RedisHValReturn(table string, data string) string {
	val, err := redisdb.HGet(ReturnRDTable(table), data).Result()
	if err == nil {
		return val
	}
	return ""
}

type RedisMultiple struct {
	Item []RedisItem
}

func ReturnEmailMultiplObj(emails []string) []interface{} {
	vars, err := redisdb.HMGet(ReturnRDTable(settings.RedisTable), emails...).Result()
	if err != nil {
		log.Printf("We got problem when quering redis from multiple results: %v\n", err)
	}
	//items := RedisMultiple{}
	return vars
}

func RedisHMDelMultiple(table string, emails []string) bool {
	//fmt.Printf("RedisHMDelMultiple from table: %s vars: %v\n", ReturnRDTable(table), emails)
	_, err := redisdb.HDel(ReturnRDTable(table), emails...).Result()
	if err != nil {
		log.Printf("We got problem when deleting from multiple results: %v\n", err)
		return false
	}
	return true
}

func RedisHDelSingle(table string, val string) bool {
	err := redisdb.HDel(ReturnRDTable(table),val)
	if err != nil {
		return false
	}
	return true
}

func RedisHMSetMultiple(table string, emails map[string]interface{}) bool {
	//err := redisdb.Do("HMSET", ReturnRDTable(table), emails)
	_, err := redisdb.HMSet(ReturnRDTable(table), emails).Result()
	if err != nil {
		log.Printf("RedisHMSetMultiple: We got problem when setting the backend storage as it shows the following error: %v vars: %v\n", err, emails)
		return false
	}
	return true
}

func RedisIncrProviderBy(table string, email string, incrby int64) bool {
	provider := ReturnDomainFromEmail(email)
	if provider != "" && len(provider) > 1 {
		_, err := redisdb.HIncrBy(table, provider, incrby).Result()
		if err != nil {
			log.Printf("RedisIncrProviderBy: We got problem when increasing the counter of %s provider: %s with the following error: %v vars: %d\n", table, provider, err, incrby)
			return false
		}
		return true
	}
	return false
}

func GetApplianceApiURL(app string) string {
	val, err := redisdb.HGet("appliances", app).Result()
	if err == nil {
		return val
	}
	return ""
}