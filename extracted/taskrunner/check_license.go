package main

import (
     "fmt"
     "crypto/x509"
     "io/ioutil"
     "net/http"
     "os"
     "crypto/tls"
     "net/url"
     "strings"
     "encoding/json"
     "errors"
     "github.com/gobuffalo/packr/v2"
)

var (
     LICENSE_HOST = "https://localhost:4242"
     LICENSE_FILE = "LICENSE"
     LICENSING = "yes"
)

func EnabledLicensing() bool {
if LICENSING == "yes" {
return true
}
return false
}

func TruncateMaterial(str string) string {
str = strings.Replace(str, "\t", "", -1)
str = strings.Replace(str, "\n", "", -1)
return str
}


func ChangeCampaignError(status string) {
// License error types
// 1001 license file not found
// 1002 cannot access license file (maybe permissions are hardened)
// 1003 Unable to initialize primary cryptographic set
// 1004 Unable to access remote license server
// 1005 Invalid output from license server, maybe some spoofing to avoid licensing accoured
// 1006 Unable to unserialize license data from server, illegal attempt to use illegal server
// 1007 Invalid license
        fmt.Printf("Error: %s\n",status)
}


func VerifyRemotely(serverURL string, licenseFile string) (verified bool, err error) {
	form := url.Values{}
        lic_exist := false
        // accurately detect where is the LICENSE file exists
        // current working program directory
        if _, err := os.Stat(fmt.Sprintf("%s/%s",ProgramPath,licenseFile)); err == nil {
           licenseFile = fmt.Sprintf("%s/%s",ProgramPath,licenseFile)
           lic_exist = true
        }
        // ../ from path
        if  _, err := os.Stat(fmt.Sprintf("%s/../%s",ProgramPath,licenseFile)); err == nil && lic_exist == false {
           licenseFile = fmt.Sprintf("%s/../%s",ProgramPath,licenseFile)
           lic_exist = true
        }
        if lic_exist == false {
	   ChangeCampaignError("License 1001")
        }

        tmp_lic, err := ioutil.ReadFile(licenseFile)
        if err != nil {
	        ChangeCampaignError("License 1002")
         }
        licenseKey := TruncateMaterial(fmt.Sprintf("%s",tmp_lic))

	form.Add("token", licenseKey)

	request, _ := http.NewRequest(http.MethodPost, serverURL+"/license/verify", strings.NewReader(form.Encode()))
	request.Header.Set("Content-Type", "application/x-www-form-urlencoded")

       	caCertPool := x509.NewCertPool()
        box := packr.New("LIC", "../CA/")
        certs, err := box.FindString("CA_ROOT.crt")
        if err != nil {
		ChangeCampaignError("License 1003")
        }

        if ok := caCertPool.AppendCertsFromPEM([]byte(certs)); !ok {
                fmt.Println("using default system crypto certs only")
        }

	client := &http.Client{
		Transport: &http.Transport{
			TLSClientConfig: &tls.Config{
		                InsecureSkipVerify: false,
				RootCAs: caCertPool,
			},
		},
	}

	resp, err := client.Do(request)
	if err != nil {
		ChangeCampaignError("License 1004")
		return false, err
	}

	var res map[string]interface{}
	bytes, err := ioutil.ReadAll(resp.Body)
	if err != nil {
                ChangeCampaignError("License 1005")
		return false, err
	}

	err = json.Unmarshal(bytes, &res)
	if err != nil {
		ChangeCampaignError("License 1006")
		return false, err
	}

	errMsg, ok := res["error"]
	if ok {
      	        ChangeCampaignError("License 1007")
		return false, errors.New(errMsg.(string))
	}
        /// only there are output of good license
	return res["valid"].(bool), nil
}

func testcert() {
        verified, err := VerifyRemotely(LICENSE_HOST, LICENSE_FILE)
	if err == nil && verified {
		return
	} else {
		os.Exit(1)
		return
	}
}


