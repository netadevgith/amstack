package main

import (
	"log"
	"net/mail"
	"strings"

	gomail "gopkg.in/gomail.v2"
)

type SMTPRequest struct {
	ServerIP  string            `json:"server_ip"`
	Port      int               `json:"port"`
	FromEmail string            `json:"from_email"`
	FromName  string            `json:"from_name"`
	ToEmail   string            `json:"to_email"`
	Subject   string            `json:"subject"`
	Body      string            `json:"body"`
	Headers   map[string]string `json:"headers"`
}

func encodeRFC2047(String string) string {
	// use mail's rfc2047 to encode any string
	addr := mail.Address{Address: String}
	return strings.Trim(addr.String(), " <@>")
}

func SMTPSend(req SMTPRequest) {
	static_port := req.Port
	m := gomail.NewMessage(gomail.SetEncoding(gomail.Base64))
	m.SetBody("text/html", req.Body)
	m.SetHeader("From", m.FormatAddress(req.FromEmail, req.FromName))
	m.SetHeader("To", req.ToEmail)
	m.SetHeader("Subject", req.Subject)
	// some custom headers
	for h, k := range req.Headers {
		m.SetHeader(h, k)
	}
	// Send the email.
	d := gomail.NewPlainDialer(req.ServerIP, static_port, "", "")
	// Display an error message if something goes wrong
	if err := d.DialAndSend(m); err != nil {
		log.Printf("Error on sendout: %s!\n", err)
		if strings.Contains(err.Error(), "timeout") {
			log.Printf("Timeout detected on server %s...\n", req.ServerIP)
		}

	} else {
		log.Printf("mail to: %s sent ok\n", req.ToEmail)
	}
}
