LICENSING=$(shell cat LICENSING)
LDFLAGS_f1=-ldflags "-X main.LICENSING=${LICENSING}"
VERSION=`cat VERSION`
BUILD=`date "+%F-%T"`
COMMIT=`git rev-parse HEAD`
DEBUG=debug
#DEBUG=nodebug

LDFLAGS_runner=-ldflags "-w -s -X main.Version=${VERSION} -X main.Build=${BUILD} -X main.Commit=${COMMIT} -X main.LICENSING=${LICENSING} -X main.DBG=${DEBUG}"

all: build

deps:
	go get github.com/streadway/amqp
	go get golang.org/x/sys/unix
	go get github.com/shirou/gopsutil
	go get gopkg.in/ini.v1
	go get github.com/lib/pq
	go get github.com/jmoiron/sqlx
	go get github.com/go-redis/redis
	go get github.com/gobuffalo/packr/v2/...
build:
# build 3.x branch
	cd gosender-src && \
		packr2 clean && packr2 && \
		go build ${LDFLAGS_f1} -o ../gosender gosender.go check_license.go deferred.go && \
		cd -

clean:
	packr2 clean
	if [ -f gosender ] ; then rm gosender ; fi


.PHONY: all docker clean encode
