package main

import (
        "github.com/go-redis/redis"
        "fmt"
        "os"
        "time"
//        "path/filepath"
//        "errors"
        "math/rand"
        "strings"
)

// random symbols generation
// {rndsym[30,40]}

var (
        redisdb     *redis.Client
        campaign string
        campLogfile *os.File
        ProgramPath = ""
)


func init() {
        rand.Seed(time.Now().UnixNano())
}


func RandSymCount(min, max int) string {
length := rand.Intn(max-min) + min
var letter = []rune("±!@#$%^&*()_+=-[]{};'\\:\"|,./<>?`~")

        b := make([]rune, length)
        for i := range b {
                b[i] = letter[rand.Intn(len(letter))]
        }
        return string(b)
}

func main() {
text := "Labas cia yra randomas visiskas"
val := strings.Replace(text, "randomas", string(RandSymCount(5,20)), -1)
fmt.Printf("Replaced text is: %s\n",val)
}
