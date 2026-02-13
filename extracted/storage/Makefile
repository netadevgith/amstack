# This how we want to name the binary output
BINARY=storage

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
	go build ${LDFLAGS_f1} -o $(BINARY) -v ./src/
	./${BINARY}

build:
	go build ${LDFLAGS_f1} -o ${BINARY} ./src/

# Installs our project: copies binaries
install:
	go install ${LDFLAGS_f1}

# Cleans our project: deletes binaries
clean:
	if [ -f ${BINARY} ] ; then rm ${BINARY} ; fi

.PHONY: clean install
