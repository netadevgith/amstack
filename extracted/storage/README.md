## Counters totals
To update counters from the text file with emails database use the following command
./storage --count-data-from --file emails.txt
To count from 0 (reset counters) and read new values from emails dump text file use the following command
./storage --count-data-from --file emails.txt  --force

## Data in storage
To import new providers, add one to providers.txt and run the following command (it will automatically extract the specified providers and put them to the SQL)
./storage --extract-providers --file emails.txt 

## Recount counters from local redis database
./storage --cache-counters --force


## normalize text files in unix (new lines replacements)
# IN UNIX ENVIRONMENT: convert DOS newlines (CR/LF) to Unix format.
sed 's/.$//'               # assumes that all lines end with CR/LF
sed 's/^M$//'              # in bash/tcsh, press Ctrl-V then Ctrl-M
sed 's/\x0D$//'            # works on ssed, gsed 3.02.80 or higher

# IN UNIX ENVIRONMENT: convert Unix newlines (LF) to DOS format.
sed "s/$/`echo -e \\\r`/"            # command line under ksh
sed 's/$'"/`echo \\\r`/"             # command line under bash
sed "s/$/`echo \\\r`/"               # command line under zsh
sed 's/$/\r/'                        # gsed 3.02.80 or higher
Use sed -i for in-place conversion e.g. sed -i 's/..../' file.
OR
awk 'BEGIN{RS="\r|\n|\r\n|\n\r";ORS="\n"}{print}' windows_or_macos.txt > unix.txt
