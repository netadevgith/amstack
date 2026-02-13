package main

// Logas - is the logging function, that pass text to the log object and later to the log file
func Logas(text string, a ...interface{}) {
        if settings.Logging == true {
                Log.Printf(text, a...)
        }
}
