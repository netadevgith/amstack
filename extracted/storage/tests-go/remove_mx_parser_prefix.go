package main


import (
"fmt"
"strings"
)



func main() {

mx := "smtp.aliceposta.it[82.57.200.133]:25"
if strings.Contains(mx, "[") {
// we need to replace the mx to the valid and understandable one
mx = strings.Split(mx, "[")[0]
}

fmt.Printf("Replaced mx: %s\n",mx)

}
