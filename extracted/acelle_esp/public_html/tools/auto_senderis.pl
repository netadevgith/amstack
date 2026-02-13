#!/usr/bin/perl -w
use strict;
use warnings;
use DBI;
use JSON::XS 'decode_json';
my $dbh;
my %env; # global mysql settings


sub read_lavonelis {
# example $env{DB_PASSWORD}
open INI, $ENV{"HOME"}."/public_html/.env"||die "Neatidarau lavonelio aplinkos failo :(\n";
while (<INI>) {
chomp;
if (/^\W*(\w+)=?(\w.+)\W*(#.*)?$/) {
my $setting = $1; # setting
#chomp($setting);
my $value = $2; # value
$env{$setting} = $value;
} # end if regex
} # end while
close INI;
} # end read lavonelis

sub dbconn {
$dbh = DBI->connect("DBI:mysql:".$env{DB_DATABASE}.":".$env{DB_HOST}, $env{DB_USERNAME}, $env{DB_PASSWORD} ,{ PrintError => 1, RaiseError => 0 }) or die "Unable to connect to MySQL\n";
}

#my $com;
#my $FILE=`find public_html/storage/app/keys/ -name \*.parm -type f -printf '%T@ %p\n' | sort -n | tail -1 | cut -f2- -d" "`;
#chomp($FILE);


#open(my $fh, '<:encoding(UTF-8)', $FILE) or die "Could not open file '$FILE' $!";
#while (my $command = <$fh>) {
#chomp $command;
#$com = $command;
#}
#close($fh);
#my @comarr = split /::/, $com;
#print "$comarr[0] \"$comarr[1]\" \"$comarr[2]\" \"$comarr[3]\" \"$comarr[4]\" $comarr[5] $comarr[6] $comarr[7] $comarr[8] $comarr[9]";
#system("$comarr[0] \"$comarr[1]\" \"$comarr[2]\" \"$comarr[3]\" \"$comarr[4]\" $comarr[5] $comarr[6] $comarr[7] $comarr[8] $comarr[9]");

sub rewrite_html {
my ($html_file,$domain) = @_;
my $path = $html_file.'.html2';
print "Replacing all urls with $domain in html file $html_file\n";
open(IN, '<'.$html_file) or die $!;
open(OUT, '>'.$path) or die $!;
while(<IN>)
{
$_ =~ s/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,5}(\:[0-9]+)?\/?/http:\/\/$domain\//g;
print OUT $_;
}
close(IN);
close(OUT);
return $path;
}

sub json_rewrite {
my ($json_file, $domain, $mail_to) = @_;
my $path = $json_file.'.json2';
local $/ = undef;
open FILE, $json_file or die "Couldn't open file: $!";
my $raw = <FILE>;
close FILE;
my $json = decode_json($raw);
$json->{email} = $mail_to;
$json->{tracking_url} =~ s/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,5}(\:[0-9]+)?\/?/http:\/\/$domain\//g; 
$json->{unsubscribe_url} =~ s/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,5}(\:[0-9]+)?\/?/http:\/\/$domain\//g;
$json->{update_url} =~ s/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,5}(\:[0-9]+)?\/?/http:\/\/$domain\//g;
my $encoded = JSON::XS->new->utf8->encode($json);
open(OUT, '>'.$path) or die $!;
print OUT $encoded;
close(OUT);
return $path;
}

sub parse_file {
my ($parm_file, $campid, $subject, $from_email, $from_name, $reply_to, $mail_to) = @_;
print $parm_file."\n";
open(my $fh, '<:encoding(UTF-8)', $parm_file) or die "Could not open parameter file! It seems that the compaign lacks of sending test mail first!\n";
my $com;
while (my $command = <$fh>) {
chomp $command;
$com = $command;
}
close($fh);
my @comarr = split /::/, $com;
my $reply = $comarr[0];
my @dom = split /@/, $from_email;
my $domain = $dom[1];
my $html_file = rewrite_html($comarr[8],$domain);
my $json_file = json_rewrite($comarr[7],$domain, $mail_to);
#print "$comarr[0] \"$subject\" \"$from_name\" \"$from_email\" $comarr[4] $comarr[5] $comarr[6] $json_file $html_file $domain\n";
system("$comarr[0] \"$subject\" \"$from_name\" \"$from_email\" $comarr[4] $comarr[5] $comarr[6] $json_file $html_file $domain")
# we should run mailsend here
}


read_lavonelis();
dbconn();
# foreach all the maillists that have imap settings saved
my $sth = $dbh->prepare("select campaigns.id as campid, campaigns.uid as campuid, campaigns.subject as subject, campaigns.from_email, campaigns.from_name, campaigns.reply_to, mail_lists.imap_mail from campaigns_lists_segments inner join campaigns on campaigns_lists_segments.campaign_id = campaigns.id inner join mail_lists on campaigns_lists_segments.mail_list_id = mail_lists.id where mail_lists.imap_host is not NULL and mail_lists.imap_mail is not NULL and mail_lists.imap_pass is not NULL and mail_lists.imap_spam is not null and mail_lists.imap_host != '' and mail_lists.imap_mail != '' and mail_lists.imap_pass != '' and mail_lists.imap_spam != ''");
$sth->execute();
my $results = $sth->fetchall_arrayref({});
for (@{$results}) {
print "Trying to get campaign settings ...for: $_->{imap_mail}\n";
parse_file("public_html/storage/app/keys/sendas-".$_->{campuid}.".parm", $_->{campid}, $_->{subject}, $_->{from_email}, $_->{from_name}, $_->{reply_to}, $_->{imap_mail});
}
