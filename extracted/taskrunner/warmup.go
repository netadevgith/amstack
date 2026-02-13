package main

import (
	"encoding/json"
	"fmt"
	"log"
	"time"
)

const (
	WARMUP_REQ_START  = 1
	WARMUP_REQ_PAUSE  = 2
	WARMUP_REQ_STOP   = 3
	WARMUP_REQ_STATUS = 4
	WARMUP_REQ_DELETE = 5
	workerPoolSize    = 4
)

var (
	WarmupJobs map[string]*Job
)

type WarmupRequest struct {
	Uid  string `json:"uid"`
	Type int    `json:"type"` // 1 start, 2 pause, 3 stop, 4 status
}

type SendoutDetails struct {
	Tracking string `db:"warmup_default_tracking"`
	FromName string `db:"warmup_default_name"`
	Subject  string `db:"warmup_default_subject"`
	Text     string `db:"warmup_default_text"`
}

type Pool struct {
	Id         int    `db:"id"`
	Uid        string `db:"uid"`
	Name       string `db:"name"`
	Email      string `db:"email"`
	ServerIP   string `db:"server_ip"`
	ServerPass string `db:"server_pass"`
	//	Total   int      `db:"total"`
	//	PerH    int      `db:"perh"`
	//	Status  string   `db:"status"`
	SendoutDetails SendoutDetails
	Ips            []string `db:"ip_address"`
	Phrases        map[int]Phrase
}

func (p Pool) NextPhrase(current_phrase int) int {
	// if the last phrase return 0
	for _, e := range p.Phrases {
		if e.Phrase > current_phrase {
			return e.Phrase
		}
	}
	return 0
}

type Phrase struct {
	Id     int    `db:"id"`
	Uid    string `db:"uid"`
	Total  int    `db:"total"`
	PerH   int    `db:"perh"`
	Phrase int    `db:"phrase"`
}

type Job struct {
	Uid      string // the same as the pool uid
	Chan     chan string
	Phrase   int
	Status   string // paused, running
	Total    int
	Sendout  int
	Speed    int
	TimeLeft int
}

func (j *Job) DumpOjb() Job {
	jb := Job{Uid: j.Uid, Phrase: j.Phrase, Status: j.Status, Total: j.Total, Sendout: j.Sendout, Speed: j.Speed, TimeLeft: j.TimeLeft}
	return jb
}

// marshal only specified fields for that specific object type Job, skip Chan field
func (s Job) MarshalJSON() ([]byte, error) {
	return json.Marshal(struct {
		Uid      string
		Phrase   int
		Status   string
		Total    int
		Sendout  int
		Speed    int
		TimeLeft int
	}{
		Uid:      s.Uid,
		Phrase:   s.Phrase,
		Status:   s.Status,
		Total:    s.Total,
		Sendout:  s.Sendout,
		Speed:    s.Speed,
		TimeLeft: s.TimeLeft,
	})
}

func LoadJobs(jbs []Job) {
	for _, val := range jbs {
		err, Poolas := ReturnPoolObj(val.Uid)
		if err != nil {
			fmt.Printf("Error quering the pool uid: %s, maybe the pool are already deleted ? %s\n", val.Uid, err)
			return
		}
		fmt.Printf("Trying to load job: %#v\n", val)
		Jobas := &Job{Uid: val.Uid, Phrase: val.Phrase, Status: val.Status, Chan: make(chan string), Total: val.Total, Sendout: val.Sendout, Speed: val.Speed, TimeLeft: val.TimeLeft}
		fmt.Printf("New obj: %#v\n", Jobas)
		WarmupJobs[val.Uid] = Jobas
		fmt.Printf("Pool uid: %s have been restored from the program data dump\n", val.Uid)
		if val.Status == "running" || val.Status == "paused" {
			go Worker(Jobas.Chan, Jobas, Poolas)
		}
	}
}

func Worker(jobChan <-chan string, job *Job, poolas Pool) {
	/*
		    10k emails išsiustu per 10 - 12h galima paklaida.
			10000 * ip_skaicius  = total (50000)
			per_h * 60 * 60 = time seconds (432000)
			(time seconds / total) = greitis seconds (8,64)
			greitis seconds * 10000000 = greitis microseconds (8640000)

			taip pat cia pradzioje reikia tikrinti phrase ir status jeigu status done eiti i phrase 2 ir t.t.

	*/
	paused := false
	if job.Status == "paused" {
		paused = true
	}
	log.Printf("Worker started for pool %s\n", job.Uid)
	time_started := time.Now()
	//time_full_seconds := poolas.PerH * 60 * 60
	// New impl
	time_full_seconds := poolas.Phrases[job.Phrase].PerH * 60 * 60
	// load last time if possible
	if job.TimeLeft > 0 {
		log.Printf("Pool seems to be already initialized somehow, continuing where we left...\n")
		time_full_seconds = job.TimeLeft
	}

	log.Printf("DEBUG: %#v", job)
	sending_speed := int(float64(time_full_seconds) / float64(poolas.Phrases[job.Phrase].Total) * float64(1000000) * float64(len(poolas.Ips)))
	//sending_speed := int(float64(poolas.Phrases[job.Phrase].Total) * float64(time_full_seconds) * float64(1000000))
	fmt.Printf("%d / %d * 1000000 = %d\n", time_full_seconds, poolas.Phrases[job.Phrase].Total, sending_speed)
	//fmt.Printf("We got measurements of: %d seconds of full time and sending speed of: %d\n", time_full_seconds, sending_speed)
	job.Speed = sending_speed

	for {
		select {
		case sig := <-jobChan:
			if sig == "done" {
				fmt.Printf("Received done signal, terminating the job for pool %s\n", job.Uid)
				job.Status = "Interrupted"
				job.Sendout = 0
				job.Speed = 0
				job.TimeLeft = 0
				return
			}
			if sig == "pause" {
				fmt.Printf("Received toggle pause for pool: %s\n", job.Uid)
				if paused {
					paused = false
				} else {
					paused = true
				}
			}
		default:
		}

		if paused {
			fmt.Printf("Pause affected for pool %s!\n", job.Uid)
			job.Status = "paused"
			time.Sleep(2 * time.Second)
			continue
		}
		job.Status = "running"
		// suskaiciuot koks turi but laikas tarp kiekvieno emailo siunciant 120h 30k emailu
		if job.Sendout >= job.Total {
			fmt.Printf("We have finished all the sendout for the pool %#v\n", poolas)
			next_phrase := poolas.NextPhrase(job.Phrase)
			if next_phrase > 0 {
				// going to one level up
				// deprecating the current job, create the new one and proceed further!!!!
				Jobas := &Job{Uid: job.Uid, Phrase: next_phrase, Status: "", Chan: make(chan string), Total: poolas.Phrases[next_phrase].Total}
				WarmupJobs[poolas.Uid] = Jobas
				fmt.Printf("Pool uid: %s moving towards phrase: %d\n", job.Uid, next_phrase)
				go Worker(Jobas.Chan, Jobas, poolas)
				fmt.Printf("Exiting old thread of uid %s\n", job.Uid)
				return

			} else {
				// there are no more phrases lets done it completely
				job.Status = "complete"
				return
			}
		}
		//fmt.Printf("Worker working... [%s]\n", paused)
		for _, ip := range poolas.Ips {
			fmt.Printf("Sending email using ip %s FromEmail: %s FromName: %s ToEmail: %s subject: %s body: %s\n", ip, poolas.SendoutDetails.Tracking, poolas.SendoutDetails.FromName, poolas.Email, poolas.SendoutDetails.Subject, poolas.SendoutDetails.Text)
			// smtp sendout process
			mail := SMTPRequest{ServerIP: ip, Port: 2525, FromEmail: poolas.SendoutDetails.Tracking, FromName: poolas.SendoutDetails.FromName, ToEmail: poolas.Email, Subject: poolas.SendoutDetails.Subject, Body: poolas.SendoutDetails.Text}
			SMTPSend(mail)
			job.Sendout++
		}
		time_elapsed := int(time.Since(time_started) / time.Second)
		//fmt.Printf("time elapsed: %d\n", time_elapsed)
		time_left := time_full_seconds - time_elapsed
		//fmt.Printf("time left: %d\n", time_left)
		job.TimeLeft = time_left
		// it will show then it actually completes
		//then := now.Add(time.Duration(431986) * time.Second)
		//fmt.Println(then)
		fmt.Printf("---- STATUS FOR WARMUP POOL %s (%d of %d) status: %s speed: %d time left: %s\n", job.Uid, job.Sendout, job.Total, job.Status, job.Speed, TimeLeftAvg(time_left))
		time.Sleep(time.Duration(job.Speed) * time.Microsecond)
	}
}

func TimeLeftAvg(taimas int) string {
	now := time.Now()
	then := now.Add(time.Duration(taimas) * time.Second)
	difference := then.Sub(now)
	total := int(difference.Seconds())
	days := int(total / (60 * 60 * 24))
	hours := int(total / (60 * 60) % 24)
	minutes := int(total/60) % 60
	seconds := int(total % 60)
	return fmt.Sprintf("%d days %d:%d:%d", days, hours, minutes, seconds)
}

func StopJob(uid string) {
	fmt.Printf("Sending stop command to warmup pool %s\n", uid)
	WarmupJobs[uid].Chan <- "done"
}

func WarmupExists(uid string) bool {
	if _, ok := WarmupJobs[uid]; ok {
		return true
	}
	return false
}

func WarmupRunning(uid string) bool {
	if _, ok := WarmupJobs[uid]; ok {
		if WarmupJobs[uid].Status == "running" || WarmupJobs[uid].Status == "paused" {
			return true
		}
	}
	return false
}

func PauseJob(uid string) {
	fmt.Printf("Sending toggle pause command to warmup pool %s\n")
	WarmupJobs[uid].Chan <- "pause"
}

func StartWarmup(uid string, phrase int) {
	if phrase == 0 {
		phrase = 1
	}
	//hours := (hour*time.Hour)
	err, Pool := ReturnPoolObj(uid)
	if err != nil {
		fmt.Printf("Error quering the pool: %s\n", err)
		return
	}
	fmt.Printf("We got pool %v#\n", Pool)
	if WarmupRunning(uid) == false {
		Jobas := &Job{Uid: uid, Chan: make(chan string), Status: "new", Total: Pool.Phrases[phrase].Total, Sendout: 0, Phrase: phrase}
		WarmupJobs[uid] = Jobas
		go Worker(Jobas.Chan, Jobas, Pool)
	} else {
		fmt.Printf("Warmup with uid %s is already running\n", uid)
	}
}

func RemoveJob(uid string) {
	fmt.Printf("Removing pool job %s from memory\n", uid)
	delete(WarmupJobs, uid)
	fmt.Printf("Pool jobs left: %#v\n", WarmupJobs)
}

func DisplayJobs(uid string) {
	if WarmupRunning(uid) {
		fmt.Printf("-------------------> STATUS FOR WARMUP POOL %s (%d of %d)\n", uid, WarmupJobs[uid].Sendout, WarmupJobs[uid].Total)
	} else {
		fmt.Printf("There is no running pool with uid %s\n", uid)
	}
}

// func ReturnPhrases(uid string) (error, []Phrase) {
// 	dbconn()
// 	Phrases := []Phrase{}
// 	err := mysqldb.Select(&Phrases, fmt.Sprintf("SELECT warmups_phrases.id,warmups_phrases.uid,total,perh,warmups_phrases.phrase from warmups_phrases inner join warmups on warmups_phrases.warmup_id = warmups.id WHERE warmups.uid='%s'", uid))
// 	if err != nil {
// 		fmt.Printf("Error quering ip for pool: %s\n", err)
// 	}
// 	return err, Phrases
// }

func ReturnPoolObj(uid string) (error, Pool) {
	dbconn()
	PoolRow := Pool{}
	query := fmt.Sprintf("SELECT id,uid,name,email,server_ip,server_pass from warmups WHERE uid='%s'", uid)
	err := mysqldb.Get(&PoolRow, query)
	if err != nil {
		fmt.Printf("Error quering for pool: %s\n", err)
		//query = fmt.Sprintf("SELECT ip_address from warmups_ips inner join warmups on warmups_ips.warmup_id = warmups.id WHERE warmups.uid='%s'", uid)
	}
	err = mysqldb.Select(&PoolRow.Ips, fmt.Sprintf("SELECT ip_address from warmups_ips inner join warmups on warmups_ips.warmup_id = warmups.id WHERE warmups.uid='%s'", uid))
	if err != nil {
		fmt.Printf("Error quering ip for pool: %s\n", err)
	}
	obj := []Phrase{}
	err = mysqldb.Select(&obj, fmt.Sprintf("SELECT warmups_phrases.id,warmups_phrases.uid,total,perh,warmups_phrases.phrase from warmups_phrases inner join warmups on warmups_phrases.warmup_id = warmups.id WHERE warmups.uid='%s' order by warmups_phrases.phrase", uid))
	if err != nil {
		fmt.Printf("Error quering for pool phrases: %s\n", err)
	}
	PoolRow.Phrases = make(map[int]Phrase)
	for _, dm := range obj {
		PoolRow.Phrases[dm.Phrase] = dm
	}
	QR := "SELECT reiksm as warmup_default_tracking FROM nustatymai where pavad = ? limit 1"
	if err = mysqldb.QueryRow(QR, "warmup_default_tracking").Scan(&PoolRow.SendoutDetails.Tracking); err != nil {
		PoolRow.SendoutDetails.Tracking = "info@domain.com"
	}
	QR = "SELECT reiksm as warmup_default_name FROM nustatymai where pavad = ? limit 1"
	if err = mysqldb.QueryRow(QR, "warmup_default_name").Scan(&PoolRow.SendoutDetails.FromName); err != nil {
		PoolRow.SendoutDetails.FromName = "John"
	}
	QR = "SELECT reiksm as warmup_default_subject FROM nustatymai where pavad = ? limit 1"
	if err = mysqldb.QueryRow(QR, "warmup_default_subject").Scan(&PoolRow.SendoutDetails.Subject); err != nil {
		PoolRow.SendoutDetails.Subject = "Simple subject"
	}
	QR = "SELECT reiksm as warmup_default_text FROM nustatymai where pavad = ? limit 1"
	if err = mysqldb.QueryRow(QR, "warmup_default_text").Scan(&PoolRow.SendoutDetails.Text); err != nil {
		PoolRow.SendoutDetails.Text = "Simple text"
	}
	fmt.Printf("Got pool object: %#v\n", PoolRow)
	return err, PoolRow
}

func WarmupPool(msg Message) {
	fmt.Printf("Got message %s\n", msg.Val)
	reqv := WarmupRequest{}
	err := json.Unmarshal([]byte(msg.Val), &reqv)
	if err != nil {
		fmt.Printf("Unable to unmarshal warmup request, aborting...\n", err)
		return
	}
	fmt.Printf("Unmarshal ok %v\n", reqv)
	switch reqv.Type {
	case WARMUP_REQ_START:
		fmt.Printf("Start warmup received from uid: %s\n", reqv.Uid)
		// test email
		//SMTPSend(mail)
		StartWarmup(reqv.Uid, 1)
	case WARMUP_REQ_PAUSE:
		//fmt.Printf("Pause requested for pool %s\n", reqv.Uid)
		PauseJob(reqv.Uid)
	case WARMUP_REQ_STOP:
		//fmt.Printf("Stop requested for pool %s\n", reqv.Uid)
		StopJob(reqv.Uid)
	case WARMUP_REQ_STATUS:
		//fmt.Printf("Status requested for pool %s\n", reqv.Uid)
		DisplayJobs(reqv.Uid)
	case WARMUP_REQ_DELETE:
		RemoveJob(reqv.Uid)
	}

}

//// jobs
