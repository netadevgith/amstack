package main

import (
	"encoding/json"
	"fmt"
	"log"
	"strings"
	"time"
)

func AddToBlackListStorage(redis_table_queue string, sql_table_name string, blacklist_type int) {
	dbconn()
	all_items, err := redisdb.HGetAll(redis_table_queue).Result()
	if err == nil {
		for _, value := range all_items {
			item := SQLBlacklistItem{}
			err := json.Unmarshal([]byte(value), &item)
			if err == nil {
				if item.Type > 0 {
					if settings.Debug {
						fmt.Printf("Inserting record to SQL: %v\n", item)
					}
					//sql_query := fmt.Sprintf("INSERT INTO %s (val,type,reason) VALUES(?,?,?) ON DUPLICATE KEY UPDATE id  = id;", sql_table_name)

					sql_query := fmt.Sprintf("INSERT INTO %s (val,type,reason) VALUES('%s',%d,'%s') ON DUPLICATE KEY UPDATE id  = id", sql_table_name, item.Val, item.Type, item.Reason)
					_, err := mysqldb.Exec(sql_query)

					if err != nil && settings.Debug {
						fmt.Printf("Got error while inserting record to mysql (maybe it exists aready ?): %s\n", err)
						Logas("Got error while inserting record to mysql (maybe it exists aready ?): %s", err)
						//continue
					}
					redisdb.HDel(redis_table_queue, item.Val).Result()

				} else if item.Type < 0 {
					if settings.Debug {
						fmt.Printf("Deleting email record from SQL: %v\n", item)
					}
					// we should reimplement this by only using Exec without any Prepare statement, because all that shit just overloads mysql, and nothing more
					sql_query := fmt.Sprintf("DELETE FROM %s WHERE val = ? limit 1;", sql_table_name)
					stmt, err := mysqldb.Prepare(sql_query)
					if err == nil {
						_, err = stmt.Exec(item.Val)
						if err != nil && settings.Debug {
							fmt.Printf("Got error while deleting mysql record: %s\n", err)
							Logas("Got error while deleting mysql record: %s\n", err)
							continue
						}
						redisdb.HDel(redis_table_queue, item.Val).Result()
					} else {
						fmt.Printf("Unable to prepare the SQL statement: %s\n", err)
					}
				}
			}
		}
	} else {
		log.Printf("We got problems in retrieving data from: %s (Big number of resources?): %s\nTry to inncrease the ReadTimeout: setting on redis connection\n", redis_table_queue, err)
		if settings.Debug {
			fmt.Printf("We got problems in retrieving data from: %s (Big number of resources?): %s\nTry to inncrease the ReadTimeout: setting on redis connection\n", redis_table_queue, err)
		}
	}
}

func AddOpenersClickers(redis_table_queue string, sql_table_name string, openers_clickers_type int) {
	dbconn()
	all_items, err := redisdb.HGetAll(redis_table_queue).Result()
	if err == nil {
		if openers_clickers_type == 20 {
			for _, value := range all_items {
				item := Opener{}

				err := json.Unmarshal([]byte(value), &item)
				if err == nil {
					if settings.Debug {
						fmt.Printf("Inserting opener record to SQL: %v\n", item)
					}
					item.Mx = GetProviderMX(ReturnDomainFromEmail(item.Email))
					sql_query := fmt.Sprintf("INSERT INTO %s (email,ip_address,server_ip,server_ptr,user_agent,location,date_added,mx,deployment,domain,campaign,maillist) VALUES('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s') ON DUPLICATE KEY UPDATE id  = id", sql_table_name, item.Email, item.Ip_address, item.Server_ip, item.Server_ptr, item.User_agent, item.Location, item.Date_added, item.Mx, item.Deployment, item.Domain, item.Campaign, item.Maillist)
					if settings.Debug {
						fmt.Printf("Doing: %s\n", sql_query)
					}
					_, err := mysqldb.Exec(sql_query)
					if err != nil && settings.Debug {
						fmt.Printf("Got error while inserting record to mysql (maybe it exists aready ?): %s\n", err)
						Logas("Got error while inserting record to mysql (maybe it exists aready ?): %s", err)
						//continue
						// FIXME we should handle this error somehow, don't know right now :(
					}
					redisdb.HDel(redis_table_queue, item.Email).Result()

				}
			}
		}
		if openers_clickers_type == 21 {
			for _, value := range all_items {
				item := Clicker{}
				err := json.Unmarshal([]byte(value), &item)
				if err == nil {
					if settings.Debug {
						fmt.Printf("Inserting opener record to SQL: %v\n", item)
					}
					item.Mx = GetProviderMX(ReturnDomainFromEmail(item.Email))
					sql_query := fmt.Sprintf("INSERT INTO %s (email,ip_address,server_ip,server_ptr,user_agent,location,date_added,mx,deployment,domain,campaign,maillist) VALUES('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s') ON DUPLICATE KEY UPDATE id  = id", sql_table_name, item.Email, item.Ip_address, item.Server_ip, item.Server_ptr, item.User_agent, item.Location, item.Date_added, item.Mx, item.Deployment, item.Domain, item.Campaign, item.Maillist)
					if settings.Debug {
						fmt.Printf("Doing: %s\n", sql_query)
					}
					_, err := mysqldb.Exec(sql_query)
					if err != nil && settings.Debug {
						fmt.Printf("Got error while inserting record to mysql (maybe it exists aready ?): %s\n", err)
						Logas("Got error while inserting record to mysql (maybe it exists aready ?): %s", err)
						//continue
						// FIXME we should handle this error somehow, don't know right now :(
					}
					redisdb.HDel(redis_table_queue, item.Email).Result()

				}
			}
		}
	} else {
		log.Printf("We got problems in retrieving data from: %s (Big number of resources?): %s\nTry to inncrease the ReadTimeout: setting on redis connection\n", redis_table_queue, err)
		if settings.Debug {
			fmt.Printf("We got problems in retrieving data from: %s (Big number of resources?): %s\nTry to inncrease the ReadTimeout: setting on redis connection\n", redis_table_queue, err)
		}
	}
}

func AddDeliveries(redis_table_queue string, sql_table_name string) {
	dbconn()
	all_items, err := redisdb.HGetAll(redis_table_queue).Result()
	if err == nil {
		for _, value := range all_items {
			item := Delivery{}
			err := json.Unmarshal([]byte(value), &item)
			if err == nil {
				if settings.Debug {
					fmt.Printf("Inserting record to SQL: %v\n", item)
				}
				if item.Mx == "" {
					item.Mx = GetProviderMX(ReturnDomainFromEmail(item.Email))
				} else {
					// fix the issue, then the parser returns relay host as [host]ip:port
					if strings.Contains(item.Mx, "[") {
						// we need to replace the mx to the valid and understandable one
						item.Mx = strings.Split(item.Mx, "[")[0]
					}
				}

				sql_query := fmt.Sprintf("INSERT INTO %s (email,campaign,server_ip,mx,deployment,status,date_added) VALUES('%s','%s','%s','%s','%s','%s','%s') ON DUPLICATE KEY UPDATE id  = id", sql_table_name, item.Email, item.Campaign, item.Server_ip, item.Mx, item.Deployment, item.Status, item.Date_added)
				if settings.Debug {
					fmt.Printf("Doing: %s\n", sql_query)
				}
				_, err := mysqldb.Exec(sql_query)

				if err != nil && settings.Debug {
					fmt.Printf("Got error while inserting record to mysql (maybe it exists aready ?): %s\n", err)
					Logas("Got error while inserting record to mysql (maybe it exists aready ?): %s", err)
					//continue
				}
				redisdb.HDel(redis_table_queue, item.Email).Result()

			}
		}
	} else {
		log.Printf("We got problems in retrieving data from: %s (Big number of resources?): %s\nTry to inncrease the ReadTimeout: setting on redis connection\n", redis_table_queue, err)
		if settings.Debug {
			fmt.Printf("We got problems in retrieving data from: %s (Big number of resources?): %s\nTry to inncrease the ReadTimeout: setting on redis connection\n", redis_table_queue, err)
		}
	}
}

func QueueWatcher() {
	for {
		for i := -4; i <= 12; i++ {
			_, tbltmp := ReturnBlTableByTypeID(i)
			table_queue := ReturnRDTable(tbltmp)
			Size, err := redisdb.HLen(table_queue).Result()
			if err == nil {
				//fmt.Printf("Size of %s is %d\n", table_queue, Size)
				if Size > settings.QueueOversizeLimit {
					fmt.Printf("The table: %s is over limits: %d, we need to add fresh data to SQL server...\n", table_queue, settings.QueueOversizeLimit)
					AddToBlackListStorage(table_queue, ReturnSqlTableByTypeID(i), i)
				}
			}
		}
		// here we should implement watcher for the openers,clickers and deliveries
		// openers 20
		_, tbltmp := ReturnBlTableByTypeID(20)
		table_queue := ReturnRDTable(tbltmp)
		Size, err := redisdb.HLen(table_queue).Result()
		if err == nil {
			//fmt.Printf("Size of %s is %d\n", table_queue, Size)
			if Size > settings.QueueOversizeLimit {
				fmt.Printf("The table: %s is over limits: %d, we need to add fresh data to SQL server...\n", table_queue, settings.QueueOversizeLimit)
				AddOpenersClickers(table_queue, ReturnSqlTableByTypeID(20), 20)
			}
		}
		// clickers 21
		_, tbltmp = ReturnBlTableByTypeID(21)
		table_queue = ReturnRDTable(tbltmp)
		Size, err = redisdb.HLen(table_queue).Result()
		if err == nil {
			//fmt.Printf("Size of %s is %d\n", table_queue, Size)
			if Size > settings.QueueOversizeLimit {
				fmt.Printf("The table: %s is over limits: %d, we need to add fresh data to SQL server...\n", table_queue, settings.QueueOversizeLimit)
				AddOpenersClickers(table_queue, ReturnSqlTableByTypeID(21), 21)
			}
		}
		// deliveries 30
		_, tbltmp = ReturnBlTableByTypeID(30)
		table_queue = ReturnRDTable(tbltmp)
		Size, err = redisdb.HLen(table_queue).Result()
		if err == nil {
			//fmt.Printf("Size of %s is %d\n", table_queue, Size)
			if Size > settings.QueueOversizeLimit {
				fmt.Printf("The table: %s is over limits: %d, we need to add fresh data to SQL server...\n", table_queue, settings.QueueOversizeLimit)
				AddDeliveries(table_queue, ReturnSqlTableByTypeID(30))
			}
		}
		time.Sleep(time.Second * time.Duration(settings.BlQueueWatcherInterval))

	}
}
