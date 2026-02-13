package main

import (
	"fmt"
	"net"
)

var (
Mxes = make(map[string]string, 0)
)

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

func main() {
//Mxes["online.nl"] = "mx.online.nl"

fmt.Printf("kpnmail.nl found as %s\n",GetProviderMX("kpnmail.nl"))
fmt.Printf("telenet.be found as %s\n",GetProviderMX("telenet.be"))
}

