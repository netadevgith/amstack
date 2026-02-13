package main

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"strconv"
	"strings"
	"path/filepath"
	"math/rand"
	routing "github.com/qiangxue/fasthttp-routing"
	"github.com/valyala/fasthttp"
)

// heya
func APIPool() {
	router := routing.New()
	router.Get("/", func(c *routing.Context) error {
		c.Response.Header.Set("Content-Type", "text/html; charset=utf-8")
		fmt.Fprintf(c, "Currently we have these available APIs:<br>")
		fmt.Fprintf(c, "<a href=\"/api/v1/\">API v1</a>")
		return nil
	})
	api := router.Group("/api/v1")
	api.Get("/", func(c *routing.Context) error {
		fmt.Fprintf(c, "API v1 Documentation:\n")
		fmt.Fprintf(c, APIDocumentationV1())
		c.Response.SetStatusCode(http.StatusOK)
		return nil
	})
	// api.Get("/users/<username>", func(c *routing.Context) error {
	// 	c.Set("Content-Type", "text/json")
	// 	fmt.Fprintf(c, "Name: %v\n", c.Param("username"))
	// 	if CheckAPIAuthorization(c) {
	// 		fmt.Fprintf(c, "You are now authorized!\n")
	// 		fmt.Fprintf(c, "RemoteAddr: %s\n", c.RemoteAddr())
	// 		fmt.Fprintf(c, "Authorization: %s\n", c.Request.Header.Peek("Authorization"))
	// 		log.Printf("Authorization from %s auth key %s", c.RemoteAddr(), c.Request.Header.Peek("Authorization"))
	// 	} else {
	// 		c.Response.SetStatusCode(http.StatusUnauthorized)
	// 	}

	// 	return nil
	// })

	// Stable - Openers submit api call
	api.Post("/openers/submit", func(c *routing.Context) error {
		c.Set("Content-Type", "text/json")
		if CheckAPIAuthorization(c) {
			table, queue_table := ReturnBlTableByTypeID(OPENERS_TYPE)
			jsonas := c.Request.Body()
			openers := make([]Opener, 0)
			err := json.Unmarshal([]byte(jsonas), &openers)
			if err == nil {
				tmpdata := make(map[string]interface{}, len(openers))
				for _, value := range openers {
					clean_val := LowerString(strings.TrimRight(value.Email, "\r\n"))
					// check if exists
					if IfRedisHeExists(table, clean_val) == false {
						RedisIncrProviderBy(ReturnRDTable(CACHELIST_OPENERS_COUNT), clean_val, 1)
						// specify the value for each map
						tmpdata[clean_val], _ = json.Marshal(value)
					}
				}
				//fmt.Printf("DEBUG VARS: %v\n", tmpdata)
				if len(tmpdata) > 0 {
					if RedisHMSetMultiple(table, tmpdata) && RedisHMSetMultiple(queue_table, tmpdata) {
						c.Response.SetStatusCode(http.StatusOK)
						return nil
					}
				}
				c.Response.SetStatusCode(http.StatusOK)
				//c.Response.SetStatusCode(http.StatusNoContent)
				return nil

			} else {
				log.Printf("Unable to parse request that i've got: %s", err)
				c.Response.SetStatusCode(http.StatusBadRequest)
				return nil
			}

		} else {
			c.Response.SetStatusCode(http.StatusUnauthorized)
			return nil
		}
		return nil
	})
	// Stable - Clickers submit api call
	api.Post("/clickers/submit", func(c *routing.Context) error {
		c.Set("Content-Type", "text/json")
		if CheckAPIAuthorization(c) {
			table, queue_table := ReturnBlTableByTypeID(CLICKERS_TYPE)
			jsonas := c.Request.Body()
			clickers := make([]Clicker, 0)
			err := json.Unmarshal([]byte(jsonas), &clickers)
			if err == nil {
				tmpdata := make(map[string]interface{}, len(clickers))
				for _, value := range clickers {
					clean_val := LowerString(strings.TrimRight(value.Email, "\r\n"))
					// check if exists
					if IfRedisHeExists(table, clean_val) == false {
						RedisIncrProviderBy(ReturnRDTable(CACHELIST_CLICKERS_COUNT), clean_val, 1)
						// specify the value for each map
						tmpdata[clean_val], _ = json.Marshal(value)
					}
				}
				//fmt.Printf("DEBUG VARS: %v\n", tmpdata)
				if len(tmpdata) > 0 {
					if RedisHMSetMultiple(table, tmpdata) && RedisHMSetMultiple(queue_table, tmpdata) {
						c.Response.SetStatusCode(http.StatusOK)
						return nil
					}
				}
				c.Response.SetStatusCode(http.StatusOK)
				//c.Response.SetStatusCode(http.StatusNoContent)
				return nil

			} else {
				log.Printf("Unable to parse request that i've got: %s", err)
				c.Response.SetStatusCode(http.StatusBadRequest)
				return nil
			}

		} else {
			c.Response.SetStatusCode(http.StatusUnauthorized)
			return nil
		}
		return nil
	})

	// Stable - Deliveries submit api call
	api.Post("/deliveries/submit", func(c *routing.Context) error {
		c.Set("Content-Type", "text/json")
		if CheckAPIAuthorization(c) {
			table, queue_table := ReturnBlTableByTypeID(DELIVERED_TYPE)
			jsonas := c.Request.Body()
			deliveries := make([]Delivery, 0)
			err := json.Unmarshal([]byte(jsonas), &deliveries)
			if err == nil {
				tmpdata := make(map[string]interface{}, len(deliveries))
				for _, value := range deliveries {
					clean_val := LowerString(strings.TrimRight(value.Email, "\r\n"))
					// check if exists
					if IfRedisHeExists(table, clean_val) == false {
						RedisIncrProviderBy(ReturnRDTable(CACHELIST_DELIVERIES_COUNT), clean_val, 1)
						// specify the value for each map
						tmpdata[clean_val], _ = json.Marshal(value)
					}
				}
				//fmt.Printf("DEBUG VARS: %v\n", tmpdata)
				if len(tmpdata) > 0 {
					if RedisHMSetMultiple(table, tmpdata) && RedisHMSetMultiple(queue_table, tmpdata) {
						c.Response.SetStatusCode(http.StatusOK)
						return nil
					}
				}
				c.Response.SetStatusCode(http.StatusOK)
				//c.Response.SetStatusCode(http.StatusNoContent)
				return nil

			} else {
				log.Printf("Unable to parse request that i've got: %s", err)
				c.Response.SetStatusCode(http.StatusBadRequest)
				return nil
			}

		} else {
			c.Response.SetStatusCode(http.StatusUnauthorized)
			return nil
		}
		return nil
	})

	api.Get("/mails/get/<email>", func(c *routing.Context) error {
		c.Set("Content-Type", "text/json")
		//fmt.Fprintf(c, "Quering email: %v\n", c.Param("email"))
		if CheckAPIAuthorization(c) {
			// fmt.Fprintf(c, "Authorization accepted!\n")
			// fmt.Fprintf(c, "RemoteAddr: %s\n", c.RemoteAddr())
			// fmt.Fprintf(c, "Authorization: %s\n", c.Request.Header.Peek("Authorization"))
			log.Printf("Authorization from %s auth key %s", c.RemoteAddr(), c.Request.Header.Peek("Authorization"))
			if IfEmailExists(LowerString(c.Param("email"))) == false {
				c.Response.SetStatusCode(http.StatusNotFound)
				return nil
			}
			data, err := json.Marshal(ReturnEmailObj(LowerString(c.Param("email"))))
			if err != nil {
				c.Response.SetStatusCode(http.StatusNotFound)
				return nil
			}
			c.Write(data)
			//fmt.Fprint(c, data)
			return nil
		} else {
			c.Response.SetStatusCode(http.StatusUnauthorized)
			return nil
		}

		return nil
	})

	api.Get("/ping/<val>", func(c *routing.Context) error {
		c.Set("Content-Type", "text/json")
		pong := c.Param("val")
		//fmt.Printf("Got ping: %s\n", pong)
		if pong != "" {
			type SimpleAnswer struct {
				Answer string `json:"answer"`
			}
			anwser := SimpleAnswer{}
			anwser.Answer = pong
			data, _ := json.Marshal(anwser)
			c.Write(data)
			c.Response.SetStatusCode(http.StatusOK)
			return nil
		} else {
			c.Response.SetStatusCode(http.StatusNotFound)
			return nil
		}

		c.Response.SetStatusCode(http.StatusBadRequest)

		return nil
	})

	// This api endpoint will tell the metrics statistics for probider
	api.Get("/statprovider/<val>", func(c *routing.Context) error {
		c.Set("Content-Type", "text/json")
		if CheckAPIAuthorization(c) {
			provider := LowerString(c.Param("val"))

			c.Response.Header.Set("Access-Control-Allow-Origin", "*") // allow to share statistics in cross domain, without origin restrictions
			//fmt.Printf("Got ping: %s\n", pong)
			if provider != "" {
				type SimpleAnswer struct {
					Blacklisted int64 `json:"blacklisted"`
					Openers     int64 `json:"openers"`
					Clickers    int64 `json:"clickers"`
					Deliveries  int64 `json:"deliveries"`
					Total       int64 `json:"total"`
				}
				answer := SimpleAnswer{}
				// we should get the real values from redis backend
				res, err := redisdb.HGet(ReturnRDTable(CACHELIST_EMAILSBL_COUNT), provider).Int64()
				if err == nil {
					answer.Blacklisted = res
				}
				res, err = redisdb.HGet(ReturnRDTable(CACHELIST_OPENERS_COUNT), provider).Int64()
				if err == nil {
					answer.Openers = res
				}
				res, err = redisdb.HGet(ReturnRDTable(CACHELIST_CLICKERS_COUNT), provider).Int64()
				if err == nil {
					answer.Clickers = res
				}
				res, err = redisdb.HGet(ReturnRDTable(CACHELIST_DELIVERIES_COUNT), provider).Int64()
				if err == nil {
					answer.Deliveries = res
				}
				res, err = redisdb.HGet(ReturnRDTable(CACHELIST_STORAGE_COUNT), provider).Int64()
				if err == nil {
					answer.Total = res
				}
				data, _ := json.Marshal(answer)
				c.Write(data)

				c.Response.SetStatusCode(http.StatusOK)
				return nil
			} else {
				c.Response.SetStatusCode(http.StatusNotFound)
				return nil
			}

			c.Response.SetStatusCode(http.StatusBadRequest)

			return nil
		} else {
			c.Response.SetStatusCode(http.StatusUnauthorized)
			return nil
		}
		return nil
	})

	// this api endpoint will tell global statistics about all system metrics
	api.Get("/overall/stats", func(c *routing.Context) error {
		c.Set("Content-Type", "text/json")
		if CheckAPIAuthorization(c) {
			c.Response.Header.Set("Access-Control-Allow-Origin", "*") // allow to share statistics in cross domain, without origin restrictions
			type SimpleAnswer struct {
				BlacklistedEmails  int64 `json:"blacklistedemails"`
				BlacklistedDomains int64 `json:"blacklisteddomains"`
				BlacklistedNames   int64 `json:"blacklistednames"`
				BlacklistedMX      int64 `json:"blacklistedmx"`
				TotalProviders     int64 `json:"totalproviders"`
				Openers            int64 `json:"openers"`
				Clickers           int64 `json:"clickers"`
				Deliveries         int64 `json:"deliveries"`
				Total              int64 `json:"total"`
			}
			answer := SimpleAnswer{}
			// we should get the real values from redis backend
			res, err := redisdb.HLen(ReturnRDTable(settings.RedisBlacklistsEmailsTable)).Result()
			if err == nil {
				answer.BlacklistedEmails = res
			}
			res, err = redisdb.HLen(ReturnRDTable(settings.RedisBlacklistsDomainTable)).Result()
			if err == nil {
				answer.BlacklistedDomains = res
			}
			res, err = redisdb.HLen(ReturnRDTable(settings.RedisBlacklistsNameTable)).Result()
			if err == nil {
				answer.BlacklistedNames = res
			}
			res, err = redisdb.HLen(ReturnRDTable(settings.RedisBlacklistsMXTable)).Result()
			if err == nil {
				answer.BlacklistedMX = res
			}
			res, err = redisdb.HLen(ReturnRDTable(CACHELIST_STORAGE_COUNT)).Result()
			if err == nil {
				answer.TotalProviders = res
			}
			res, err = redisdb.HLen(ReturnRDTable(settings.RedisOpenersTable)).Result()
			if err == nil {
				answer.Openers = res
			}
			res, err = redisdb.HLen(ReturnRDTable(settings.RedisClickersTable)).Result()
			if err == nil {
				answer.Clickers = res
			}
			res, err = redisdb.HLen(ReturnRDTable(settings.RedisDeliveryTable)).Result()
			if err == nil {
				answer.Deliveries = res
			}
			res, err = redisdb.Get(ReturnRDTable("absolutely_total")).Int64()
			if err == nil {
				answer.Total = res
			}
			data, _ := json.Marshal(answer)
			c.Write(data)
			c.Response.SetStatusCode(http.StatusOK)
			return nil
		} else {
			c.Response.SetStatusCode(http.StatusUnauthorized)
			return nil
		}
		return nil
	})

	/// -------------------- Blacklists area ------------------------

	// THIS API ENDPOINT IS BEING USED IN PRODUCTION
	// Submit blacklist item
	api.Post("/blacklists/set/<type>", func(c *routing.Context) error {
		c.Set("Content-Type", "text/json")
		blacklist_type, _ := strconv.Atoi(c.Param("type"))
		if CheckAPIAuthorization(c) {
			// detect the blacklist type and define the correct table for information storation
			table, queue_table := ReturnBlTableByTypeID(blacklist_type)
			reason := string(c.Request.Header.Peek("Reason")[:])
			jsonas := c.Request.Body()
			//fmt.Printf("Got table: %s\n", table)
			//log.Printf("Got request for multiple emails query: %s", c.Request.Body())
			var emails []string
			err := json.Unmarshal([]byte(jsonas), &emails)
			if err == nil {
				// prepare the item
				//var tmpdata map[string]interface{}
				if blacklist_type > 0 {
					tmpdata := make(map[string]interface{}, len(emails))
					tmpdata_queue := make(map[string]interface{}, len(emails))
					for _, value := range emails {
						clean_val := LowerString(strings.TrimRight(value, "\r\n"))
						// check if exists
						if IfRedisHeExists(table, clean_val) == false {
							RedisIncrProviderBy(ReturnCacheTableByOrigins(blacklist_type), clean_val, 1)
							// specify the value for each map
							tmpdata[clean_val] = blacklist_type
							tmpdata_queue[clean_val], _ = json.Marshal(SQLBlacklistItem{Val: clean_val, Type: blacklist_type, Reason: reason})
						}

					}
					//fmt.Printf("DEBUG VARS: %v\n", tmpdata)
					//fmt.Printf("DEBUG VARS2: %+v\n", tmpdata)
					if len(tmpdata) > 0 {
						if RedisHMSetMultiple(table, tmpdata) && RedisHMSetMultiple(queue_table, tmpdata_queue) {
							c.Response.SetStatusCode(http.StatusOK)
							return nil
						}
					}
					c.Response.SetStatusCode(http.StatusOK)
					//c.Response.SetStatusCode(http.StatusNoContent)
					return nil
				} else if blacklist_type < 0 && blacklist_type > BLACKLIST_SHOW_RECORDS {
					// delete emails
					if settings.Debug {
						log.Printf("Got request to delete email from blacklist\n")
					}
					tmpdata_queue := make(map[string]interface{}, len(emails))
					for _, value := range emails {
						clean_val := LowerString(strings.TrimRight(value, "\r\n"))
						// check if exists
						if IfRedisHeExists(table, clean_val) == true {
							RedisIncrProviderBy(ReturnCacheTableByOrigins(blacklist_type), clean_val, -1)
							// specify the value for each map
							tmpdata_queue[clean_val], _ = json.Marshal(SQLBlacklistItem{Val: clean_val, Type: blacklist_type, Reason: reason})
						}
					}
					// TODO add this to if statement together
					if len(tmpdata_queue) > 0 {
						RedisHMDelMultiple(table, emails)
						if RedisHMSetMultiple(queue_table, tmpdata_queue) {
							c.Response.SetStatusCode(http.StatusOK)
							return nil
						}
					}
					c.Response.SetStatusCode(http.StatusOK)
					//c.Response.SetStatusCode(http.StatusNoContent)
					return nil

				} else if blacklist_type == BLACKLIST_SHOW_RECORDS {
					// Check the existance of the records - emails/domains/names/mx'es
					fmt.Printf("This type of email submission requires some data to return :)\n")
					tmpdata := make(map[string]interface{}, len(emails))
					type ReturnStruct struct {
						Email         string `json:"email"`
						Blacklisted   bool   `json:"blacklisted"`
						BlacklistType string `json:"bl_type"`
					}
					for _, value := range emails {
						clean_val := LowerString(strings.TrimRight(value, "\r\n"))
						// specify the value for each map
						blacklisted := false
						var bl_type string
						blacklisted1, err := redisdb.HExists(ReturnRDTable(settings.RedisBlacklistsDomainTable), clean_val).Result()
						if err == nil && blacklisted1 {
							bl_type, _ = redisdb.HGet(ReturnRDTable(settings.RedisBlacklistsDomainTable), clean_val).Result()
							blacklisted = true
						}
						blacklisted2, err := redisdb.HExists(ReturnRDTable(settings.RedisBlacklistsEmailsTable), clean_val).Result()
						if err == nil && blacklisted2 {
							bl_type, _ = redisdb.HGet(ReturnRDTable(settings.RedisBlacklistsEmailsTable), clean_val).Result()
							blacklisted = true
						}
						blacklisted3, err := redisdb.HExists(ReturnRDTable(settings.RedisBlacklistsMXTable), clean_val).Result()
						if err == nil && blacklisted3 {
							bl_type, _ = redisdb.HGet(ReturnRDTable(settings.RedisBlacklistsMXTable), clean_val).Result()
							blacklisted = true
						}
						blacklisted4, err := redisdb.HExists(ReturnRDTable(settings.RedisBlacklistsNameTable), clean_val).Result()
						if err == nil && blacklisted4 {
							bl_type, _ = redisdb.HGet(ReturnRDTable(settings.RedisBlacklistsNameTable), clean_val).Result()
							blacklisted = true
						}
						tmpdata[clean_val] = ReturnStruct{Email: clean_val, Blacklisted: blacklisted, BlacklistType: bl_type}
					}
					returnObj, _ := json.Marshal(tmpdata)
					c.Write(returnObj)
					c.Response.SetStatusCode(http.StatusOK)
					return nil

				}
			} else {
				log.Printf("Unable to parse request that i've got: %s", err)
				c.Response.SetStatusCode(http.StatusBadRequest)
				return nil
			}

		} else {
			c.Response.SetStatusCode(http.StatusUnauthorized)
			return nil
		}
		return nil
	})

	// Show details about the specific email from the blacklists
	api.Get("/blacklists/get/<email>", func(c *routing.Context) error {
		c.Set("Content-Type", "text/json")
		if CheckAPIAuthorization(c) {
			if settings.Debug {
				log.Printf("/blacklists/get/<email> API Call from %s with authorization: %s\n", c.RemoteAddr(), c.Request.Header.Peek("Authorization"))
			}
			var data string = LowerString(c.Param("email"))
			if data != "" {
				if strings.Contains(data, "@") && strings.Contains(data, ".") {
					// email
					if settings.Debug {
						fmt.Printf("We got the email: %s\n", data)
					}
					if IfRedisHeExists(settings.RedisBlacklistsEmailsTable, data) {
						id := RedisHValReturn(settings.RedisBlacklistsEmailsTable, data)
						BLitem := ReturnBlItem(id, data)
						retdata, err := json.Marshal(BLitem)
						if err != nil {
							c.Response.SetStatusCode(http.StatusNoContent)
							return nil
						}
						c.Write(retdata)
						c.Response.SetStatusCode(http.StatusOK)
					} else {
						c.Response.SetStatusCode(http.StatusNoContent)
					}
					return nil
				} else if strings.Contains(data, ".") && strings.Contains(data, "@") == false {
					// domain
					if settings.Debug {
						fmt.Printf("We got the domain: %s\n", data)
					}
					if IfRedisHeExists(settings.RedisBlacklistsDomainTable, data) {
						id := RedisHValReturn(settings.RedisBlacklistsDomainTable, data)
						BLitem := ReturnBlItem(id, data)
						retdata, err := json.Marshal(BLitem)
						if err != nil {
							c.Response.SetStatusCode(http.StatusNoContent)
							return nil
						}
						c.Write(retdata)
						c.Response.SetStatusCode(http.StatusOK)

					} else {
						c.Response.SetStatusCode(http.StatusNoContent)

					}
					return nil
				} else {
					// name
					if settings.Debug {
						fmt.Printf("We got the name: %s\n", data)
					}
					if IfRedisHeExists(settings.RedisBlacklistsNameTable, data) {
						id := RedisHValReturn(settings.RedisBlacklistsNameTable, data)
						BLitem := ReturnBlItem(id, data)
						retdata, err := json.Marshal(BLitem)
						if err != nil {
							c.Response.SetStatusCode(http.StatusNoContent)
							return nil
						}
						c.Write(retdata)
						c.Response.SetStatusCode(http.StatusOK)
					} else {
						c.Response.SetStatusCode(http.StatusNoContent)
					}
					return nil

				}
			}
		} else {
			c.Response.SetStatusCode(http.StatusUnauthorized)
		}
		return nil
	})

	// THIS API ENDPOINT IS BEING USED IN PRODUCTION
	// This function will check against MX blacklist :)
	api.Get("/blacklists/mxcheck/<dns>", func(c *routing.Context) error {
		c.Set("Content-Type", "text/json")
		if CheckAPIAuthorization(c) {
			if settings.Debug {
				log.Printf("/blacklists/mxcheck/<dns> API Call from %s with authorization: %s\n", c.RemoteAddr(), c.Request.Header.Peek("Authorization"))
			}
			var data string = LowerString(c.Param("dns"))
			if data != "" {
				if IfRedisHeExists(settings.RedisBlacklistsMXTable, data) {
					c.Response.SetStatusCode(http.StatusOK)
				} else {
					c.Response.SetStatusCode(http.StatusNoContent)
				}
			} else {
				c.Response.SetStatusCode(http.StatusBadRequest)
			}
		} else {
			c.Response.SetStatusCode(http.StatusUnauthorized)
		}
		return nil
	})

	// THIS API ENDPOINT IS BEING USED IN PRODUCTION
	// Returns true if email does exist in one of the few blacklist storages in redis
	// This functions needs to act super fast, because there will be hundreds of queries per second, so it needs to be properly optimized, ojoj
	api.Get("/blacklists/check/<email>", func(c *routing.Context) error {
		c.Set("Content-Type", "text/json")
		if CheckAPIAuthorization(c) {
			if settings.Debug {
				log.Printf("/blacklists/check/<email> API Call from %s with authorization: %s\n", c.RemoteAddr(), c.Request.Header.Peek("Authorization"))
			}
			var data string = LowerString(c.Param("email"))
			// if passed parameter is not empty
			if data != "" {
				if strings.Contains(data, "@") && strings.Contains(data, ".") {
					if settings.Debug {
						fmt.Printf("We got the email: %s\n", data)
					}
					if IfRedisHeExists(settings.RedisBlacklistsEmailsTable, data) {
						c.Response.SetStatusCode(http.StatusOK)
					} else {
						c.Response.SetStatusCode(http.StatusNoContent)
					}
					return nil
				} else if strings.Contains(data, ".") && strings.Contains(data, "@") == false {
					if settings.Debug {
						fmt.Printf("We got the domain: %s\n", data)
					}
					if IfRedisHeExists(settings.RedisBlacklistsDomainTable, data) {
						c.Response.SetStatusCode(http.StatusOK)

					} else {
						c.Response.SetStatusCode(http.StatusNoContent)

					}
					return nil
				} else {
					if settings.Debug {
						fmt.Printf("We got the name: %s\n", data)
					}
					if IfRedisHeExists(settings.RedisBlacklistsNameTable, data) {
						c.Response.SetStatusCode(http.StatusOK)
					} else {
						c.Response.SetStatusCode(http.StatusNoContent)
					}
					return nil

				}

			} else {
				c.Response.SetStatusCode(http.StatusBadRequest)
			}

		} else {
			c.Response.SetStatusCode(http.StatusUnauthorized)
		}
		return nil
	})

	// This call will populate records from sql to redis backend
	api.Post("/blacklists/populate", func(c *routing.Context) error {
		c.Set("Content-Type", "text/json")
		if CheckAPIAuthorization(c) {
			fmt.Printf("Received a request to populate data from sql database to redis backend, processing...\n")
			PopulateRedis(settings.SQLBlacklistsTable, settings.RedisBlacklistsEmailsTable)
			PopulateRedis(settings.SQLBlacklistsDomainTable, settings.RedisBlacklistsDomainTable)
			PopulateRedis(settings.SQLBlacklistsNameTable, settings.RedisBlacklistsNameTable)
			PopulateRedis(settings.SQLBlacklistsMxTable, settings.RedisBlacklistsMXTable)
			fmt.Printf("Data population process completed!\n")
			c.Response.SetStatusCode(http.StatusOK)
			return nil
		} else {
			c.Response.SetStatusCode(http.StatusUnauthorized)
		}
		return nil
	})

	// After passing hundreds of emails to this api endpoint, there will be excluded the blackisted emails and returned only clean ones :)
	api.Post("/blacklists/multiclean", func(c *routing.Context) error {
		c.Set("Content-Type", "text/json")
		if CheckAPIAuthorization(c) {
			//jsonas := c.Request.Body()
			// complete here :()
		} else {
			c.Response.SetStatusCode(http.StatusUnauthorized)
		}
		return nil
	})

	/// ---------------------- End of blacklists area -----------------

	api.Post("/mails/getmulti", func(c *routing.Context) error {
		c.Set("Content-Type", "text/json")
		if CheckAPIAuthorization(c) {
			jsonas := c.Request.Body()
			log.Printf("Got request for multiple emails query: %s", c.Request.Body())
			var dat []string
			err := json.Unmarshal([]byte(jsonas), &dat)
			if err == nil {
				args := ReturnEmailMultiplObj(dat)
				// for _, email := range args {
				// 	log.Printf("Got email: %v", email)
				// }
				data, err := json.Marshal(args)
				if err == nil {
					c.Write(data)
					return nil
				}
			} else {
				log.Printf("Unable to parse request that i've got: %s", err)
			}
			//	req := SendRequest{}
			// newqueue := new(Queue)
			// json.Unmarshal(c.Request.Body(), &req)
			// components := strings.Split(req.Email, "@")
			// username, domain := components[0], components[1]

			// if (username != "") && (domain != "") {
			// 	newqueue.email = req.Email
			// 	newqueue.from = req.From
			// 	newqueue.domain = domain
			// 	newqueue.subject = req.Subject
			// 	newqueue.body = req.Body
			// 	newqueue.priority = 3
			// 	fmt.Fprintf(c, "Email queued\n")
			// 	// paprastam form post ctx.QueryArgs().Peek("haha")
			// 	log.Printf("Requested send email to: %s we already have %d queues\n", req.Email, len(queues))
			// 	//	queues = append(queues, newqueue)
			// }

		} else {
			c.Response.SetStatusCode(http.StatusUnauthorized)
		}
		return nil
	})

	api.Post("/send", func(c *routing.Context) error {
		c.Set("Content-Type", "text/json")
		if CheckAPIAuthorization(c) {
			type SendRequest struct {
				From    string
				Email   string
				Subject string
				Body    string
			}
			//	req := SendRequest{}
			// newqueue := new(Queue)
			// json.Unmarshal(c.Request.Body(), &req)
			// components := strings.Split(req.Email, "@")
			// username, domain := components[0], components[1]

			// if (username != "") && (domain != "") {
			// 	newqueue.email = req.Email
			// 	newqueue.from = req.From
			// 	newqueue.domain = domain
			// 	newqueue.subject = req.Subject
			// 	newqueue.body = req.Body
			// 	newqueue.priority = 3
			// 	fmt.Fprintf(c, "Email queued\n")
			// 	// paprastam form post ctx.QueryArgs().Peek("haha")
			// 	log.Printf("Requested send email to: %s we already have %d queues\n", req.Email, len(queues))
			// 	//	queues = append(queues, newqueue)
			// }

		} else {
			c.Response.SetStatusCode(http.StatusUnauthorized)
		}
		return nil
	})

	// ----------------------------------------------------- LINKS -------------------------------------------
	api.Get("/links/get/<hash>", func(c *routing.Context) error {
		c.Set("Content-Type", "text/json")
		if CheckAPIAuthorization(c) {
			if settings.Debug {
				log.Printf("/links/get/<hash> API Call from %s with authorization: %s\n", c.RemoteAddr(), c.Request.Header.Peek("Authorization"))
			}
			var hash string = c.Param("hash")
			// if passed parameter is not empty
			if hash != "" {
				if IfHashExists(hash) {
					ImageItem := ReturnImageItem(hash,"")
					retdata, err := json.Marshal(ImageItem)
					if err != nil {
						c.Response.SetStatusCode(http.StatusNoContent)
						return nil
					}
					    c.Write(retdata)
						c.Response.SetStatusCode(http.StatusOK)
					} else {
						c.Response.SetStatusCode(http.StatusNotFound)
					}
					return nil

			} else {
				c.Response.SetStatusCode(http.StatusBadRequest)
			}

		} else {
			c.Response.SetStatusCode(http.StatusUnauthorized)
		}
		return nil
	})

	// Function to store image and return its hash (if image is already stored just return it's hash)
	api.Post("/links/postimg", func(c *routing.Context) error {
		// campaign uid
		// image file
		// image hash
		c.Set("Content-Type", "text/json")
		if CheckAPIAuthorization(c) {
			//hash := ctx.QueryArgs().Peek("hash")
			//jsonas := c.Request.Body()
			//log.Printf("Got request for multiple emails query: %s", c.Request.Body())
			//		ctx.QueryArgs().Peek("haha")

			upl, err := c.FormFile("img")
			if err != nil {
				log.Printf("We do not get any image file via upload\n")
				return nil
			}

		
			file, err := upl.Open()
			defer file.Close()
			extension := filepath.Ext(upl.Filename)
			randomfile := fmt.Sprintf("%d",rand.Int())
			fname := fmt.Sprintf("%s%s",randomfile,extension)
			out, err := os.OpenFile("./tmp/"+fname, os.O_WRONLY|os.O_CREATE, 0666)
			if err != nil {
				log.Printf("We got some error when writting file: %s error: %s\n", fname, err)
				RemoveFile("./tmp/"+fname)
				c.Response.SetStatusCode(http.StatusNoContent)
				return nil
			}
			defer out.Close()
			io.Copy(out, file)
			log.Printf("Uploaded new file: %s\n",fname)
			// return hash of the file
			hash, err := ReturnMD5HashOfFile("./tmp/"+fname)
			if err == nil {
				log.Printf("We got hash: %s of the file\n",hash)
				// file is uploaded, lets check if we don't have the hash of it and store file in uploads
				if (!IfHashExists(hash)) {
					// we should move file here
					err := os.Rename("./tmp/"+fname, "./uploads/"+hash+extension)
	                if err != nil {
					   log.Printf("Unable to move file: %s to uploads directory error: %s\n",fname,err)
					   c.Response.SetStatusCode(http.StatusNoContent)
					   return nil
					   }
					   // add hash to redis hset list
					   RedisHsetSingle("image_links", hash,hash+extension)
				}
				RemoveFile("./tmp/"+fname)
				ImageItem := ReturnImageItem(hash,hash+extension)
				retdata, err := json.Marshal(ImageItem)
				if err != nil {
					c.Response.SetStatusCode(http.StatusNoContent)
					return nil
				}
				c.Write(retdata)
				c.Response.SetStatusCode(http.StatusOK)
				return nil
			} else {
				log.Printf("Unable to get hash of the file...\n")
				RemoveFile("./tmp/"+fname)
				c.Response.SetStatusCode(http.StatusNoContent)
				return nil
			}

			c.Response.SetStatusCode(http.StatusNoContent)
			return nil

		} else {
			c.Response.SetStatusCode(http.StatusUnauthorized)
		}
		return nil
	})

	// submit campaign information for the link tracking
	api.Post("/links/postcamp", func(c *routing.Context) error {
		c.Set("Content-Type", "text/json")
		if CheckAPIAuthorization(c) {
			//fmt.Printf("Received a request to set campaign data for the links, processing...\n")
			rawdata := c.Request.Body()
			//log.Printf("Got request for campaign submission: %s", c.Request.Body())
			CampItem := CampaignLinks{}
			err := json.Unmarshal([]byte(rawdata),&CampItem)
			if err != nil {
				log.Printf("Error in api /links/postcamp: %s\n",err)
				c.Response.SetStatusCode(http.StatusNoContent)
				return nil
			}
			if !SubmitLinksCampaign(CampItem) {
				c.Response.SetStatusCode(http.StatusNoContent)
				return nil
			}
			log.Printf("Campaign: %s (%s) for tracking links is set ok ;-)\n",CampItem.Name,CampItem.Uid)
			c.Response.SetStatusCode(http.StatusOK)
			return nil
		} else {
			c.Response.SetStatusCode(http.StatusUnauthorized)
		}
		return nil
	})

	// post tracking link and get its name
	api.Post("/links/postlink", func(c *routing.Context) error {
		c.Set("Content-Type", "text/json")
		if CheckAPIAuthorization(c) {
			if settings.Debug {
				log.Printf("Received a request to set tracking link, processing...\n")
			}
			rawdata := c.Request.Body()
			ReturnedTracking := CreateTrackingLink(string(rawdata))
			if ReturnedTracking == "" {
				c.Response.SetStatusCode(http.StatusNoContent)
				return nil
			}
			if settings.Debug {
				log.Printf("Tracking log is set ok ;-)\n")
			}
			jsonrespond, err := json.Marshal(ReturnedTracking)
			if err != nil {
				log.Printf("Error in marshaling the tracking log output for respond: %s\n",err)
				c.Response.SetStatusCode(http.StatusNoContent)
				return nil
			}
			c.Write(jsonrespond)
			c.Response.SetStatusCode(http.StatusOK)
			return nil
		} else {
			c.Response.SetStatusCode(http.StatusUnauthorized)
		}
		return nil
	})

	fmt.Printf("Binding web API service to %s:%s...\n", settings.Bind, settings.Port)
	s := &fasthttp.Server{
		Handler:            router.HandleRequest,
		Name:               "Storage " + Version,
		MaxRequestBodySize: 32 << 20,
	}
	s.ListenAndServe(fmt.Sprintf("%s:%s", settings.Bind, settings.Port))
}

// APIDocumentationV1 - Prints all API documentation regarding the MTA version 1
func APIDocumentationV1() string {
	var doc string
	doc += "lalala"
	doc += "bbbbbb"
	doc += "cccccc"
	return doc
}

// CheckAPIAuthorization - Function checks for valid API Authorixation headers in HTTP Context
func CheckAPIAuthorization(c *routing.Context) bool {
	var AuthToken = string(c.Request.Header.Peek("Authorization")[:])
	if AuthToken != "" && len(AuthToken) > 0 {
		if AuthToken == settings.ApiAuthorization {
			return true
		}
		return false
	}
	return false
}

func IfEmailExists(email string) bool {
	exist, err := redisdb.HExists(ReturnRDTable(settings.RedisTable), email).Result()
	if err == nil {
		return exist
	}
	return false
}

func ReturnEmailObj(email string) RedisItem {
	val, err := redisdb.HGet(ReturnRDTable(settings.RedisTable), email).Result()
	item := RedisItem{}
	if err != nil {
		panic("Critical error")
	}
	err = json.Unmarshal([]byte(val), &item)
	if err != nil {
		panic("Critical error")
	}
	return item
}
