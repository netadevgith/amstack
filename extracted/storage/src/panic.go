package main

import "fmt"

// ProgramPanic - Handles the program panic on the situations that are not prepared for
func ProgramPanic(err error) {
	fmt.Printf("Program panic: %s\n", err)
	Logas("Program panic: %s\n", err)
	//        SigTermEvent(1)
	panic(err)
}
