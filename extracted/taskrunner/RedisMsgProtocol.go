package main

import (
	"encoding/json"
	"log"

	"github.com/shirou/gopsutil/load"
)

// Message - is the received message declaration
type ReRequest struct {
	Uid      string `json:"uid"`
	Type     int    `json:"type"`
	Customer int    `json:"customer"`
	Data     string `json:"data"`
}

type ReAnswer struct {
	Uid    string `json:"uid"`
	Status int    `json:"status"`
	Data   string `json:"data"`
}

const (
	REDIS_RESPOND_TYPE_QUEUE_STATUS  = 1 // queue status
	REDIS_RESPOND_TYPE_WARMUP_STATUS = 2 // warmup status
)

func RedisReturnStatus(req ReRequest) {
	msg := ReAnswer{Uid: req.Uid, Status: 0, Data: ""}
	switch req.Type {
	case REDIS_RESPOND_TYPE_QUEUE_STATUS:
		loadavg, _ := load.Avg()
		respond := map[string]interface{}{
			"level1": 0,
			"level2": priorityQueue.LenLevel(2),
			"level3": priorityQueue.LenLevel(3),
			"load":   loadavg.Load1,
		}
		json, err := json.Marshal(respond)
		if err != nil {
			log.Printf("Unable to marshal json: %s\n", err)
			msg.Data = "We are unable to marshal the results to the json"
		} else {
			msg.Status = 1
			msg.Data = string(json)
		}
	case REDIS_RESPOND_TYPE_WARMUP_STATUS:
		//log.Printf("There was a request for warmup stats\n")
		var respond = []interface{}{}
		for _, val := range WarmupJobs {
			respond = append(respond, map[string]interface{}{"uid": val.Uid, "status": val.Status, "total": val.Total, "sendout": val.Sendout, "speed": val.Speed, "leftseconds": val.TimeLeft, "phrase": val.Phrase})
		}
		json, err := json.Marshal(respond)
		if err != nil {
			log.Printf("Unable to marshal json: %s\n", err)
			msg.Data = "We are unable to marshal the results to the json"
		} else {
			msg.Status = 1
			msg.Data = string(json)
		}
	default:
		msg.Status = 0
		msg.Data = "Unable to determine the type of message request that was send to the taskrunner"
	}
	answ, err := json.Marshal(msg)
	if err != nil {
		log.Printf("Unable to marshal data for the answer to the redis channel: \n", err)
		return
	}
	//log.Printf("Returning status: %s\n", answ)
	err = redisdb.HSet("taskrunner_answer", req.Uid, answ).Err()
	if err != nil {
		log.Printf("Unable to publish back answer: %s\n", err)
	}
}

func RedisQueueRespond() {
	for {
		NewMessage := redisdb.Subscribe("taskrunner_query")
		for {
			msg, _ := NewMessage.ReceiveMessage()
			go func() {
				response := ReRequest{}
				jsonErr := json.Unmarshal([]byte(msg.Payload), &response)
				if DEBUG > 0 {
					log.Printf("Got redis request message: %s\n", msg.Payload)
				}
				if jsonErr == nil {
					go RedisReturnStatus(response)
				} else {
					log.Printf("Unable to unmarshal redis request message: %s\n", jsonErr)
				}
			}()
		}
	}
}
