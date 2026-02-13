#!/usr/bin/perl -w
# v2.0 Last revision 2018.07.20
# Perl Mail Sending Backend Engine for Acelle Mail (c) \dev\null

# apt install libjson-xs-perl libdata-uniqid-perl libhtml-format-perl libredis-perl
# go analogas: http://p.9g.lt/view.php?paste=89f5e6307942c62f98fee8c7e3de3b81
use Redis;
use JSON::XS;
use Time::localtime;
use Time::HiRes qw(usleep nanosleep gettimeofday);
use DBI;
use Try::Tiny;
use Encode qw(encode decode);
use HTML::FormatText;
use MIME::Base64;
use Try::Tiny;
use strict;
use warnings;
use Getopt::Long;
use File::Basename;
my $scriptdir = undef;
$scriptdir = dirname($0);

my $version = "2.0";
my $verbose = 1;
my $warmup = 0;
# Runtime variables
my $campuid;
my $smtphost;
my $smtpport;
my $smtpspeed;
my $deployment;
my $log_file = $scriptdir."/sender.log";
my $ssl = '';
# Set defaults
my %env; # variable to put laravel settings in
my $dbh;
my $redis = Redis->new(server => '127.0.0.1:6379', reconnect => 10);
my %opt = (
                "send" => 0,
                "log" => 0,
                "help" => 1,
        );

# Gather the options from the command line
GetOptions(\%opt,
                'send',
                'log=s' => \$log_file,
                'campuid=s' => \$campuid,
                'smtphost=s' => \$smtphost,
                'smtpport=i' => \$smtpport,
                'smtpspeed=i' => \$smtpspeed,
                'ssl=s' => \$ssl,
                'app=s' => \$deployment,
                'verbose=i' => \$verbose,
                'test',
                'help',
                'nodone',
        );

sub read_lavonelis {
# example $env{DB_PASSWORD}
open INI, $ENV{"HOME"}."/public_html/.env"|| do { write_log("Unable to read laravel environment file, exiting..."); die "Neatidarau lavonelio aplinkos failo :(\n"; };
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
$dbh = DBI->connect("DBI:mysql:database=".$env{DB_DATABASE}.";host=".$env{DB_HOST}.";port=".$env{DB_PORT}, $env{DB_USERNAME}, $env{DB_PASSWORD},{ PrintError => 1, RaiseError => 0 }) or do {
write_log("Unable to connect to MySQL with campaign $campuid, we could not update the campaign status, if it finished or no\n");
die "Unable to connect to MySQL\n"; };
}

# DBI Interface to mysql Deadlocking avoid
sub dbi_deadlock_handler
{
my $retval = 1;
    my $message = @_;
    if($message =~ m/DBD::mysql::st execute failed: Deadlock found when trying to get lock; try restarting transaction/)
    {
      my $caught=1;
      $retval=0; # found deadlock
    }
    return $retval;
}

# rotator_perk: checks if rotator_perk is globally enabled
sub rotator_enabled {
my $json = $redis->get("domains_rotator_perk");
my $rotator;
try {
$rotator = decode_json($json);
} catch {
return 0;
};
if (exists $rotator->{enabled} && defined($rotator->{enabled}) && $rotator->{enabled} eq "on") {
return 1;
}
}

# rotator_perk detects the domain what it should use on specified campaign
sub rotator_domain {
my ($uid,@domains) = @_;
my $total_lines = scalar(@domains);
print "Total domains detected: $total_lines\n";
$total_lines = 0 if ($total_lines eq "");
if ($total_lines > 1) {
print "We detected $total_lines domains in our records\n";
my $nr = $redis->get("campaign_".$uid."_selector");
if (!defined($nr)) {
$nr = 1;
} else {
$nr++;
}
$nr = 1 if ($nr > $total_lines);
print "Switching domain for $uid to NR: $nr\n";
# change the domain in the campaign
my $json = $redis->get($uid);
my $campa;
try {
$campa = decode_json($json);
} catch {
rotator_increase($uid,1);
return 0;
};
my $domain = $domains[($nr-1)];
$domain =~ s/\R//g;
if ($domain ne "") {
print "Current campaign domain is: ".$campa->{trackurl}."\n";
print "Got domain: $domain changing to it\n";
$campa->{trackurl} = $domain;
$redis->set($uid,encode_json($campa));
# end of change
$redis->set("campaign_".$uid."_selector",$nr);
}
rotator_increase($uid,1);
}

}


# rotator_perk balance increase
sub rotator_increase {
my ($uid,$val) = @_;
if ($val > 0) {
$redis->set("campaign_".$uid."_rotator",0);
} else {
$redis->incr("campaign_".$uid."_rotator");
}
return $redis->get("campaign_".$uid."_rotator");
}

# check if rotate and do rotate then
sub rotator_initiate {
my ($uid,$count,$val) = @_;
my $json = $redis->get("domains_rotator_perk");
my $rotator;
try {
$rotator = decode_json($json);
} catch {
return 0;
};
if (exists $rotator->{interval} && $count >= $rotator->{interval}) {
print "Initiating rotator on campaign $uid\n";
my @domains = split /\n/, $rotator->{domains};
rotator_domain($uid,@domains);
}
}

# increase redis, realtime counter for campaign
sub increase_counter {
my ($uid) = @_;
if ($redis->exists($uid."_counter")) {
# NEW IMPLEMENTATION
$redis->incr($uid."_counter") if (!defined($opt{'test'}));
} else {
# If variable is unset, then set zero
$redis->set($uid."_counter",0);
}
}


sub timestamp {
  my $t = localtime;
  return sprintf( "%04d-%02d-%02d %02d:%02d:%02d",
                  $t->year + 1900, $t->mon + 1, $t->mday,
                  $t->hour, $t->min, $t->sec );
}

sub write_log {
my ($text) = @_;
open(my $fh, '>>', $log_file);
print $fh "[".timestamp()."]: $text\n";
close $fh;
print "[".timestamp()."]: $text - VERBOSE MODE\n" if ($verbose >0);
}

sub get_parts {
my ($part) = @_;
return $redis->srandmember($part);
}

sub encode_trackurl {
my ($trackurl,$message_id) = @_;
my $serv = $smtphost;
$message_id =~ s/{SRVBL}/$smtphost/;
# we need to encode the message_id and replace the tracking url with it...
#write_log("Before encoding message_id is $message_id");
my $enc = encode_base64($message_id);
# replace few chars
$enc =~ s/\+/\-/;
$enc =~ s/\//_/;
$enc =~ s/=/,/;
#write_log("Encoded tracking url is $enc");
#write_log("Before replaced the trackurl is: $trackurl");
$trackurl =~ s/MESSAGE_ID/$enc/g;
return $trackurl;
}

sub randominteger {
my ($lower_limit,$upper_limit) = @_;
my $random_number = int(rand($upper_limit-$lower_limit)) + $lower_limit;
return $random_number;
}

sub random_stuff {
my ($len) = @_;
my $length = randominteger(rand(1000),rand(100000));
srand( time() ^ ($$ + ($$ << 15)) );
my @v = ( 'a', 'e', 'i', 'o', 'u', 'y');
           #, 'ai', 'ou', 'oo', 'ee', 'oi');
my @c = ( 'b', 'c', 'd', 'f', 'g', 'h', 'j',
          'k', 'l', 'm', 'n', 'p', 'q', 'r',
          's', 't', 'v', 'w', 'x', 'z', ' ');
my $nc = @c;
@c =    (@c, 'bl', 'br', 'tr', 'st', 'dr',
             'th', 'ch', 'sh');
my @d = (0..9, '_', '-', '.');
my ($flip, $str) = (0,'');
for ( 1 .. ($length - 1) ) {
  $flip++;
  if ($flip%2) {
    if ($flip==$length-1) {
      $str .= $c[rand($nc)];
    } else {
      $str .= $c[rand(@c)];
    }
  } else {
    $str .= $v[rand(@v)];
  }
}
my $re;
my $digitpos = rand(5)+3;  foreach (1..$digitpos) {
  $re .= ".";
}
$str =~ s/($re)/$1 . $d[rand(@d)]/e;
$str = ucfirst $str;
return $str;
}

# msgid, subscriber_uid,
sub prepHtml {
my ($campaign, $subscriber, $trackurl) = @_;
mkdir($scriptdir."/tmp") if (! -e $scriptdir."/tmp");
my $path = $scriptdir.'/tmp/'.$subscriber->{message_id}.'html';
# REGENERATE HTML
my $open_part = get_parts('openpart');
$open_part = "open" if ($open_part eq "");
my $click_part = get_parts('clickpart');
$click_part = "click" if ($click_part eq "");
my $unsubscribe_part = get_parts('unsubscribepart');
$unsubscribe_part = "unsubscribe" if ($unsubscribe_part eq "");
my $profile_part = get_parts('profilepart');
$profile_part = "profile" if ($profile_part eq "");
my $sourcepart = get_parts('sourcepart');
$sourcepart = "source" if ($sourcepart eq "");
# new tracking implementation, adds server support
# we need to encode $subscriber->{tracking_url} here, and pass it
my $modified_tracking = encode_trackurl($subscriber->{tracking_url},$subscriber->{open_tracking});
my $inject_open_track_url = '<img src="'.$modified_tracking.'" width="0" height="0" alt="" style="visibility:hidden" /><!-- '.$subscriber->{uid}.' -->';
# old implenentation
#my $inject_open_track_url = '<img src="'.$subscriber->{tracking_url}.'" width="0" height="0" alt="" style="visibility:hidden" /><!-- '.$subscriber->{uid}.' -->';
chomp($trackurl);
chomp($click_part);
#$trackurl =~ s/%0A//g;
#$trackurl =~ s/%20//g;
$inject_open_track_url =~ s/{DOMAIN}/$trackurl/g;
$inject_open_track_url =~ s/{OPENPART}/$open_part/g;
$subscriber->{tracking_url} =~ s/{DOMAIN}/$trackurl/g;
$subscriber->{update_url} =~ s/{DOMAIN}/$trackurl/g;
$subscriber->{update_url} =~ s/{PROFILEPART}/$profile_part/g;
$subscriber->{unsubscribe_url} =~ s/{DOMAIN}/$trackurl/g;
$subscriber->{unsubscribe_url} =~ s/{UNSUBSCRIBEPART}/$unsubscribe_part/g;
my $html = $campaign->{html};
#chomp($html);
$html =~ s/{SOURCEPART}/$sourcepart/g;
$html =~ s/{CLICKPART}/$click_part/g;
$html =~ s/%7BCLICKPART%7D/$click_part/g;
$html =~ s/{DOMAIN}/$trackurl/g;
$html =~ s/{domain}/$trackurl/g;
$html =~ s/%7Bdomain%7D/$trackurl/g;
$html =~ s/MESSAGE_ID/$subscriber->{msgid1}/g;
$html =~ s/MSGID2/$subscriber->{msgid2}/g;
$html =~ s/{UPDATE_PROFILE_URL}/$subscriber->{update_url}/g;
$html =~ s/{UNSUBSCRIBE_URL}/$subscriber->{unsubscribe_url}/g;
$html =~ s/{REPORT_URL}/http:\/\/$trackurl\/report/g;
$html =~ s/{CONTACT_NAME}//g;
$html =~ s/{CONTACT_EMAIL}//g;
$html =~ s/{EMAIL}/$subscriber->{email}/g;
$html =~ s/{RANDOM}/@{[random_stuff()]}/g;
$html =~ s/amp;//g;
# tracking image url injection
$html =~ s/<\/content>/$inject_open_track_url<\/content>/g;
# new tracking implementation should work with imported templates
#$html =~ s/<\/html>/$inject_open_track_url<\/html>/g;
# unsubscribe url injection
#my $unsubscriberis = "\n\n<br><br><br><br><br><br><center><small>Want to change how you receive these emails?<br><a href=\"".$subscriber->{unsubscribe_url}."\">Click there to unsubscribe</a></small><br><br>PA Agency B.V<br>Otterkoog (UK44554) Road 534</center><br>\n\n";
#$html =~ s/<\/html>/$unsubscriberis<\/html>/g;
# some shit
$html =~ s/<content>/<div style="display:none">@{[random_stuff()]}<\/div><content>/g;


# override all urls to our own
$html =~ s/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,7}(\:[0-9]+)?\/?/http:\/\/$trackurl\//g;
open(OUT, '>:encoding(UTF-8)',$path) or write_log("Unable to open destination url for subscriber $subscriber->{uid} and campaign uid: $campuid");
print OUT $html;
close(OUT);
return $path;
} # end function

sub switch_domain {
my ($dom_type) = @_;
my $total_lines = `wc -l < \"$scriptdir/warmup_$dom_type.txt\"`;
chomp($total_lines);
$total_lines = 0 if ($total_lines eq "");
my $nr = $redis->get($dom_type.'_selector');
if (!defined($nr)) {
$nr = 2;
} else {
$nr++;
}
$nr = 1 if ($nr > $total_lines);
write_log("Switching domain for $dom_type of warmup process to line $nr");
$redis->set($dom_type.'_selector',$nr);
}

sub get_domain {
my ($dom_type) = @_;
if (-e $scriptdir."/warmup_$dom_type.txt") {
my $nr = $redis->get($dom_type.'_selector');
$nr = 1 if not defined($nr);
$nr = 1 if ($nr eq "");
open(FILE, $scriptdir."/warmup_$dom_type.txt");
my $count = 1;
while (<FILE>)
{
  if ($count == $nr)
  {
#   close FILE;
   chomp($_);
   return $_;
  }
  $count++;
}
close FILE;
} else {
return;
}
}



sub send_mail {
my ($campaign,$subscriber) = @_;
if (defined($subscriber->{status}) && ($subscriber->{status} eq "subscribed" || $subscriber->{status} eq "opener")) {
# detect the tracking url per campaign of per global setting
my $trackurl;
if ($campaign->{trackurl} eq "") {
$trackurl = $subscriber->{track_url};
} else {
$trackurl = $campaign->{trackurl};
}
my $subject = encode("MIME-Header", $campaign->{subject});
# fix domain
my @dom = split(/\@/, $campaign->{from_email});

# here we will implement rotator_perk
my $current_count = rotator_increase($campaign->{uid},0);
if (rotator_enabled) {
# check
print "We have enabled rotator\n";
if ($current_count >= 5000) {
rotator_initiate($campaign->{uid},$current_count);
}
} 
# rotator_perk end



# warmup code
# beginning of warmup process
if ($warmup > 0) {
my $domain;
my @target = split(/\@/, $subscriber->{email});
my $gmail_limit = 10000;
my $yahoo_limit = 10000;
my $hotmail_limit = 10000;
# check if there are some of warmup process for gmail.com, yahoo.com and hotmail.com providers
# then change the send address and tracking domain info criteria
# then check how much progress have been made on the certain domain
# gmail
if ($target[1] eq "gmail.com") {
$redis->incr("gmail_counter");
my $cnt = $redis->get("gmail_counter");
if ($cnt >= $gmail_limit) {
# do domain change here
$redis->set("gmail_counter",0);
switch_domain("gmail");
}
$domain = get_domain("gmail");
}
# hotmail
if ($target[1] eq "hotmail.com") {
$redis->incr("hotmail_counter");
my $cnt = $redis->get("hotmail_counter");
if ($cnt >= $hotmail_limit) {
# do domain change here
$redis->set("hotmail_counter",0);
switch_domain("hotmail");
}
$domain = get_domain("hotmail");
}
# yahoo
if ($target[1] eq "yahoo.com") {
$redis->incr("yahoo_counter");
my $cnt = $redis->get("yahoo_counter");
if ($cnt >= $yahoo_limit) {
# do domain change here
$redis->set("yahoo_counter",0);
switch_domain("yahoo");
}
$domain = get_domain("yahoo");
}
# end
if (defined($domain) && $domain ne "") {
 # assign the trackings
 $campaign->{from_email} = $dom[0].'@'.$domain;
 $dom[1] = $domain;
 $trackurl = $domain;
}
}# warmup code end

my $html_file = prepHtml($campaign,$subscriber,$trackurl);
# make text version of html
my $text = HTML::FormatText->format_file($html_file, leftmargin => 5, rightmargin => 50);
open(OUT, '>'.$html_file.'.txt') or do {
write_log("Unable to open html>text conversion text $! on campaign: $campuid and subscriber: $subscriber->{email}");
#die $!;
};
print OUT $text;
close(OUT);
# end text version of html
my $ssl_string = "";
$ssl_string = "-ssl" if ($ssl eq "ssl");
#my $header_add = "-H \"Campuid: ".$campaign->{uid}." [$deployment]\" -H \"Precedence: bulk\" -H \"List-Unsubscribe: <mailto:".$campaign->{from_email}.">\"";
my $header_add = "";
my $pirmas = "-content-type \"multipart/alternative\" -enc-type \"quoted-printable\" -mime-type \"text/plain\" -disposition inline -attach $html_file.txt";
my $enc = "";
my $test = "-enc-type \"base64\" -mime-type \"text/html\" -disposition inline -attach";
# some security fix that allows to send to the @domain.com if not handled using regexp
if ($subscriber->{email} =~ /[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,7}/) {
my @subsplit = split(/\@/, $subscriber->{email});
if (!$redis->hexists("blacklists",$subscriber->{email})&&!$redis->hexists("blacklists_fast",$subscriber->{email})&&!$redis->hexists("blacklist_abuse",$subscriber->{email})&&!$redis->hexists("blacklist_domains",$subsplit[1])&&!$redis->hexists("blacklist_names",$subsplit[0])) {
system("/usr/local/bin/mailsend -4 $ssl_string -smtp ".$smtphost." -port ".$smtpport." -domain ".$dom[1]." -t ".$subscriber->{email}." -f ".$campaign->{from_email}." -rp \"".$campaign->{from_email}."\" -rt \"".$campaign->{from_email}."\" -rrr \"".$campaign->{from_email}."\" -sub \"".$subject."\" $pirmas ".$enc." ".$test." \"".$html_file."\" ".$header_add." -name \"".$campaign->{from_name}."\" -q&&rm -f \"".$html_file."\" \"".$html_file.".txt\" > /dev/null 2>&1&");
write_log("/usr/local/bin/mailsend -4 $ssl_string -smtp ".$smtphost." -port ".$smtpport." -domain ".$dom[1]." -t ".$subscriber->{email}." -f ".$campaign->{from_email}." -rp \"".$campaign->{from_email}."\" -rt \"".$campaign->{from_email}."\" -sub \"".$subject."\" $pirmas ".$enc." ".$test." \"".$html_file."\" ".$header_add." -name \"".$campaign->{from_name}."\" -q&&rm -f \"".$html_file."\" \"".$html_file."\.txt\" > /dev/null 2>&1&") if ($verbose > 1);
} else {
write_log("Account ".$subscriber->{email}." was found in one of our blacklists!");
}
}
increase_counter($campaign->{uid});
} else {
write_log("Subscriber: $subscriber->{email} status: $subscriber->{status} does not allow to send the email...");
exit 1;
}
}

sub usage {
write_log("Help command issued");
        # Shown with --help option passed
        print "\n".
                "   sender.pl $version - Backend mail sender for acelle project\n".
                "   Bug reports, feature requests\n".
                "\n".
                "   Actions\n".
                "      --send               Start sending email\n".
                "      --test               (optional) Run internal script test mechanism\n".
                "      --log <log file>     (optional) Log to the specified file\n".
                "\n".
                "   Options (Required by --send parameter)\n".
                "      --campuid        Campaign UID to search data in Redis\n".
                "      --smtphost       Destination mail server host\n".
                "      --smtpport       Destination mail server port\n".    
                "      --smtpspeed      Destination mail server sending speed\n".
                "      --app            Deployment app (postfix parser requirement\n".
                "   Custom send options\n".
                "      --nodone         Does not change status to done\n".
                "      --ecoding        8bit, quoted-printable\n".
                "\n";
        exit;
}

sub send_proc {
my $count = 1;
if ($redis->exists($campuid)) {
read_lavonelis();
#dbconn();
write_log("JUST TESTING! (SIMULATION MODE)...") if (defined $opt{'test'});
write_log("----------> STARTING SENDING --- Campaignid: $campuid <----------------------");
write_log("Sending the emails trough $smtphost:$smtpport with speed $smtpspeed...");
# while sending cycle
while ('true') {
while($redis->exists($campuid."_paused") && !defined $opt{'test'})  {
# if pause is issued then do sleep for 2 seconds and recheck again
    sleep(2);
   }
# continue
# check if list is not empty already and campaign does exist
my $json;
if (defined $opt{'test'} && $opt{'test'} == 1) {
$json = $redis->spop("campaign_".$campuid."_subscribers_test");
} else {
$json = $redis->spop("campaign_".$campuid."_subscribers");
}
if (defined($json) && $redis->exists($campuid)) {
my $subscriber = decode_json($json);
my $campaign = decode_json($redis->get($campuid));
if ($campaign->{auto_pause} ne 0 && $campaign->{auto_pause} eq $count) {
$redis->set($campuid."_paused");
} else {
usleep($smtpspeed);
write_log("Sending [$count] number email to: $subscriber->{email}");
## HERE MAKE FUNKCTION CALL
send_mail($campaign,$subscriber);
$count++;
}
} else {
done_sending();
exit;
}

} # end while
exit;
} else {
write_log("Campaign with such uid does not exists on the redis interface !");
exit;
}
} # end func

sub done_sending {
# check if campaign exists and update it's status
if (!defined $opt{'test'} && !defined $opt{'nodone'}) {
dbconn();
unless ($dbh->ping) { dbconn(); }
      my $query  = "UPDATE campaigns set status='done' WHERE uid = ?";
      my $sth = $dbh->prepare($query);
my $db_res=0;
while($db_res==0)
{
   $db_res=1;
   try{$sth->execute($campuid);}
   catch
   {
       write_log("We caught $_ in insertion to MySQL\n on Campaign $campuid Retrying...\n");
       $db_res=dbi_deadlock_handler($_);
       if($db_res==0){sleep 2;}
   }

}
$redis->del("campaign_".$campuid."_subscribers");
my $cmp = decode_json($redis->get($campuid));
$cmp->{status} = 'done';
$redis->set($campuid,encode_json($cmp));
write_log("------------------> Done sending the campaign: $campuid <--------------------");
} else {
$redis->del("campaign_".$campuid."_subscribers_test");
write_log("------------------> Done sending test emails for campaign: $campuid <--------------------");
}

exit;
}


if (defined $opt{'send'} && $opt{'send'} == 1) { 
if (defined($campuid)&&defined($smtphost)&&defined($smtpport)&&defined($smtpspeed)) {
send_proc();  } else {
write_log("You have not defined the required parameters!");
}
}
if (defined $opt{'help'} && $opt{'help'} == 1) { usage(); }

