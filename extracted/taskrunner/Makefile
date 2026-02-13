# This how we want to name the binary output
BINARY=taskrunner

# These are the values we want to pass for VERSION and BUILD
# git tag 1.0.1
# git commit -am "One more change after the tags"
VERSION=`cat VERSION`
BUILD=`date "+%F-%T"`
COMMIT=`git rev-parse HEAD`

# Setup the -ldflags option for go build here, interpolate the variable values
LDFLAGS_f1=-ldflags "-w -s -X main.Version=${VERSION} -X main.Build=${BUILD} -X main.Commit=${COMMIT}"

all: build

run:
	go build ${LDFLAGS_f1} -o $(BINARY) -v ./...
	./${BINARY}

deps:
	go get github.com/streadway/amqp
	go get golang.org/x/sys/unix
	go get github.com/shirou/gopsutil
	go get gopkg.in/ini.v1
	go get github.com/lib/pq
	go get github.com/jmoiron/sqlx
	go get github.com/go-redis/redis
build:
	go build ${LDFLAGS_f1} -o ${BINARY}

# Installs our project: copies binaries
install:
	go install ${LDFLAGS_f1}

# Cleans our project: deletes binaries
clean:
	if [ -f ${BINARY} ] ; then rm ${BINARY} ; fi

.PHONY: clean install
