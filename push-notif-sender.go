package main

import (
	"bufio"
	"bytes"
	"database/sql"
	"encoding/json"
	"flag"
	"fmt"
	_ "github.com/go-sql-driver/mysql"
	"io/ioutil"
	"net/http"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"
)
var db string
var table string
var dbUser string
var dbPassword string
var postId string
var postSlug string
var postTitle string
var postSummary string
var postPhoto string
var fcmKey string
var httpClient *http.Client
var sqlDb *sql.DB
var expiredAndroidTokens []string
var expiredIosTokens []string
var wg sync.WaitGroup
var logWriter *bufio.Writer
var okAndroid int
var okIos int
var pluginPath string

func main () {

	flag.StringVar(&db, "db", "", "Database name")
	flag.StringVar(&table, "table", "", "Table name")
	flag.StringVar(&dbUser, "db-user", "", "Database user")
	flag.StringVar(&dbPassword, "db-password", "", "Database user's password")
	flag.StringVar(&postId, "post-id", "", "Wordpress post ID")
	flag.StringVar(&postSlug, "post-slug", "", "Wordpress post slug")
	flag.StringVar(&postTitle, "post-title", "", "Wordpress post title")
	flag.StringVar(&postSummary, "post-summary", "", "Wordpress post excerpt")
	flag.StringVar(&postPhoto, "post-photo", "", "Wordpress post featured photo")
	flag.StringVar(&fcmKey, "fcm-key", "", "FCM server key")
	flag.StringVar(&pluginPath, "plugin-path", "", "Installation path of plugin")

	flag.Parse()

	fmt.Printf(fmt.Sprintf("Args postid=%s post-slug=%s post-title=%s post-summary=%s post-photo=%s fcm-key=%s plugin-path=%s\n", postId, postSlug, postTitle, postSummary, postPhoto, fcmKey, pluginPath))

	var err error

	f, err := os.OpenFile(fmt.Sprintf("%spush-notif-sender.log", pluginPath), os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0644)

	if err != nil {
		fmt.Printf("Error opening file: %s", err.Error())
		panic(err)
	}

	fmt.Printf("Opened file, path: %s", f.Name())

	defer f.Close()

	logWriter = bufio.NewWriter(f)

	writeLog(fmt.Sprintf("Args postid=%s post-title=%s post-summary=%s post-photo=%s", postId, postTitle, postSummary, postPhoto))

	httpClient = &http.Client{}

	sqlDb, err = sql.Open("mysql", fmt.Sprintf("%s:%s@/%s", dbUser, dbPassword, db))

	if err != nil {
		handleError(err)
	}

	defer sqlDb.Close()

	err = sqlDb.Ping()

	if err != nil {
		handleError(err)
	}

	dispatchPushNotifs()
}

func dispatchPushNotifs()  {

	startTimestamp := float64(time.Now().UnixNano())

	//time.Sleep(time.Minute)

	stmt, err := sqlDb.Prepare(fmt.Sprintf("SELECT token, platform FROM %s", table))

	if err != nil {
		panic(err.Error())
	}

	defer stmt.Close()

	var tokenBytes, platformBytes []byte
	var token, platform string

	rows, err := stmt.Query()

	var androidTokens []string
	var iosTokens []string

	for rows.Next() {
		err = rows.Scan(&tokenBytes, &platformBytes)

		if err != nil {
			panic(err.Error())
		}

		token = string(tokenBytes)
		platform = string(platformBytes)

		if platform == "android" {
			androidTokens = append(androidTokens, token)
		} else {
			iosTokens = append(iosTokens, token)
		}
	}

	writeLog(fmt.Sprintf("Found %d Android tokens and %d iOS tokens", len(androidTokens), len(iosTokens)))

	wg.Add(1)
	go func(tokens []string) {
		defer wg.Done()

		for _, entry := range tokens {
			pushAndroid(entry)
		}

	}(androidTokens)

	wg.Add(1)
	go func(tokens []string) {
		defer wg.Done()

		for _, entry := range tokens {
			pushIos(entry)
		}

	}(iosTokens)

	wg.Wait()

	writeLog(fmt.Sprintf("Push notification succesfully sent to %d Android and %d iOS devices",
		okAndroid, okIos))

	writeLog(fmt.Sprintf("There were %d expired Android tokens and %d expired iOS tokens",
		len(expiredAndroidTokens), len(expiredIosTokens)))

	if len(expiredAndroidTokens) > 0 {
		err = deleteExpiredTokens(expiredAndroidTokens)

		if err != nil {
			writeLog(fmt.Sprintf("Error deleting expired Android tokens: %s", err.Error()))
		}
	}

	if len(expiredIosTokens) > 0 {
		err = deleteExpiredTokens(expiredIosTokens)

		if err != nil {
			writeLog(fmt.Sprintf("Error deleting expired iOS tokens: %s", err.Error()))
		}
	}

	endTimestamp := float64(time.Now().UnixNano())
	duration := (endTimestamp - startTimestamp) / 1e9
	writeLog(fmt.Sprintf("All done in %.2f seconds", duration))
	writeLog("FINISHED")
}

func pushAndroid(token string) {

	var notif = map[string]interface{} {
		"to": token,
		"data": getPayload(),
	}

	jsonString, err := json.Marshal(notif)

	if err != nil {
		writeLog(fmt.Sprintf("Error marshaling map to JSON: %s", err.Error()))
		return
	}

	err, success := sendNotification(string(jsonString))

	if err != nil {
		logWriter.WriteString(fmt.Sprintf("Error sending Android notification: %s", err.Error()))
	} else if success {
		okAndroid = okAndroid + 1
	} else {
		expiredAndroidTokens = append(expiredAndroidTokens, token)
	}
}

func sendNotification(payload string) (err error, success bool) {
	r, _ := http.NewRequest(
		"POST",
		"https://fcm.googleapis.com/fcm/send",
		bytes.NewBufferString(payload),
	)

	r.Header.Add("Authorization", fmt.Sprintf("key=%s", fcmKey))
	r.Header.Add("Content-Type", "application/json")

	resp, err := httpClient.Do(r)

	if err != nil {
		return err, false
	}

	bodyBytes, err := ioutil.ReadAll(resp.Body)

	if err != nil {
		return err, false
	}

	fmt.Printf("FCM response: %s", string(bodyBytes))

	var body map[string]*json.RawMessage
	err = json.Unmarshal(bodyBytes, &body)

	if err != nil {
		return err, false
	}

	if _, ok := body["success"]; ok {

		var success int
		err = json.Unmarshal(*body["success"], &success)

		if success == 0 {
			return nil, false
		}

		return nil, true
	}

	return nil, false
}

func pushIos(token string) {

	var notif = map[string]interface{}{
		"to":           token,
		"notification": getPayload(),
		"priority":     "high",
	}

	jsonString, err := json.Marshal(notif)

	if err != nil {
		writeLog(fmt.Sprintf("Error marshaling map to JSON: %s", err.Error()))
		return
	}

	err, success := sendNotification(string(jsonString))

	if err != nil {
		logWriter.WriteString(fmt.Sprintf("Error sending iOS notification: %s", err.Error()))
	} else if success {
		okIos = okIos + 1
	} else {
		expiredIosTokens = append(expiredIosTokens, token)
	}
}

func getPayload() map[string]interface{} {

	id, _ := strconv.ParseInt(postId, 10, 64)

	var post = map[string]interface{}{
		"id":		id,
		"slug":		postSlug,
		"title":	postTitle,
		"summary":	postSummary,
		"photo":	postPhoto,
	}

	var payload = map[string]interface{}{
		"type":		"post_notification",
		"post":		post,
	}

	var data = map[string]interface{}{
		"payload":	payload,
	}

	return data
}


func deleteExpiredTokens(tokens []string) error {

	var ts []string

	for _, t := range tokens {
		ts = append(ts, fmt.Sprintf("'%s'", t))
	}

	query := fmt.Sprintf("DELETE FROM `%s` WHERE `token` IN (%s)", table, strings.Join(ts[:],","))

	stmt, err := sqlDb.Prepare(query)

	if err != nil {
		return err
	}

	defer stmt.Close()

	_, err = stmt.Exec()

	if err != nil {
		return err
	}

	return nil
}

func writeLog(message string) {
	fmt.Printf(fmt.Sprintf("%s\n", message))
	logWriter.WriteString(fmt.Sprintf("%v: %v\n", time.Now().Format("2006-01-02 15:04:05"), message))
	logWriter.Flush()
}

func handleError(err error) {
	writeLog(err.Error())
	panic(err.Error())
}
