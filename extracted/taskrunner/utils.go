package main

import (
	"bufio"
	"bytes"
	"fmt"
	"io"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/valyala/fasthttp"
)

var (
	green  = string([]byte{27, 91, 48, 48, 58, 51, 50, 109})
	yellow = string([]byte{27, 91, 48, 48, 59, 51, 51, 109})
	red    = string([]byte{27, 91, 48, 48, 59, 51, 49, 109})
	blue   = string([]byte{27, 91, 48, 48, 59, 51, 52, 109})
	white  = string([]byte{27, 91, 48, 109})
)

func getColorByStatus(code int) string {
	switch {
	case code >= 200 && code < 300:
		return green
	case code >= 300 && code < 400:
		return blue
	case code >= 400 && code < 500:
		return yellow
	default:
		return red
	}
}

func colorStatus(code int) string {
	return getColorByStatus(code) + strconv.Itoa(code) + white
}

func colorMethod(method []byte, code int) string {
	return getColorByStatus(code) + string(method) + white
}

func getHttp(ctx *fasthttp.RequestCtx) string {
	if ctx.Response.Header.IsHTTP11() {
		return "HTTP/1.1"
	}
	return "HTTP/1.0"
}

// CombinedColored is same as Combined but colored
func CombinedColored(req fasthttp.RequestHandler) fasthttp.RequestHandler {
	return fasthttp.RequestHandler(func(ctx *fasthttp.RequestCtx) {
		begin := time.Now()
		ip := GetIpAddressFromHeader(ctx)
		// url blocking
		if UrlBlocked(string(ctx.RequestURI())) {
			loggers.url.Printf("%s %v (%s) | %s | %s %s%s - %v | %s",
			fmt.Sprintf("%sBLOCKED BY URL FILTER%s",red,white),
			ip,
			ReturnLocation(ip),
			getHttp(ctx),
			colorMethod(ctx.Method(), ctx.Response.Header.StatusCode()),
			GetTrackDomainFromHeader(ctx),
			ctx.RequestURI(),
			colorStatus(ctx.Response.Header.StatusCode()),
			ctx.UserAgent(),
			)
			ctx.SetStatusCode(fasthttp.StatusNotFound)
			return
		}
		// user agent blocking
		if UserAgentBlocked(string(ctx.UserAgent())) {
			loggers.url.Printf("%s %v (%s) | %s | %s %s%s - %v | %s",
			fmt.Sprintf("%sBLOCKED BY USER AGENT FILTER%s",red,white),
			ip,
			ReturnLocation(ip),
			getHttp(ctx),
			colorMethod(ctx.Method(), ctx.Response.Header.StatusCode()),
			GetTrackDomainFromHeader(ctx),
			ctx.RequestURI(),
			colorStatus(ctx.Response.Header.StatusCode()),
			ctx.UserAgent(),
			)
			ctx.SetStatusCode(fasthttp.StatusNotFound)
			return
		}
		// ip blocking
		if IpBlocked(ip) {
			loggers.url.Printf("%s %v (%s) | %s | %s %s%s - %v | %s",
			fmt.Sprintf("%sBLOCKED BY IP FILTER%s",red,white),
			ip,
			ReturnLocation(ip),
			getHttp(ctx),
			colorMethod(ctx.Method(), ctx.Response.Header.StatusCode()),
			GetTrackDomainFromHeader(ctx),
			ctx.RequestURI(),
			colorStatus(ctx.Response.Header.StatusCode()),
			ctx.UserAgent(),
			)
			ctx.SetStatusCode(fasthttp.StatusNotFound)
			return
		}
		req(ctx)
		end := time.Now()
		loggers.url.Printf("%v (%s) | %s | %s %s%s - %v - %v | %s",
			ip,
			ReturnLocation(ip),
			getHttp(ctx),
			colorMethod(ctx.Method(), ctx.Response.Header.StatusCode()),
			GetTrackDomainFromHeader(ctx),
			ctx.RequestURI(),
			colorStatus(ctx.Response.Header.StatusCode()),
			end.Sub(begin),
			ctx.UserAgent(),
		)
	})
}


// Read a whole file into the memory and store it as array of lines
func readLines(path string) (lines []string, err error) {
    var (
        file *os.File
        part []byte
        prefix bool
    )
    if file, err = os.Open(path); err != nil {
        return
    }
    defer file.Close()

    reader := bufio.NewReader(file)
    buffer := bytes.NewBuffer(make([]byte, 0))
    for {
        if part, prefix, err = reader.ReadLine(); err != nil {
            break
        }
        buffer.Write(part)
        if !prefix {
            lines = append(lines, buffer.String())
            buffer.Reset()
        }
    }
    if err == io.EOF {
        err = nil
    }
    return
}

func StringArrayContains(source []string, target string) bool {
    for _, a := range source {
        if strings.Contains(target,a) {
            return true
        }
    }
    return false
}