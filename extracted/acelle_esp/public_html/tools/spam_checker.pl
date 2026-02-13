#!/usr/bin/perl -w
# apt install libcourriel-perl
use strict;
use warnings;
use Mail::IMAPClient;
use Courriel qw();
use MIME::Base64;
use Time::HiRes qw(sleep);
use Time::localtime;
use Data::Dumper;
use DBI;
use Redis;
my $dbh;
my %env; # global mysql settings


sub logsql {
my ($text) = @_;
my $sth = $dbh->prepare("INSERT INTO controller_logs (text) VALUES(?)");
$sth->execute($text);
}

# daro pause pagal mail_list_id ( VISAI KITA FUNKC )
sub campaign_pause {
my ($email) = @_;
my $sth = $dbh->prepare("select campaigns.uid from campaigns_lists_segments inner join campaigns on campaigns.id = campaigns_lists_segments.campaign_id where campaigns_lists_segments.mail_list_id = ?");
$sth->execute($email);
my $campaigns = $sth->fetchall_arrayref({});
for my $camp (@$campaigns) {
print "Campaign: $camp->{uid}\n";
my $redis = Redis->new(server => '127.0.0.1:6379', reconnect => 60);
# jeigu egzistuoja keywordas
if ($redis->exists($camp->{uid}."_paused")) {
$redis->del($camp->{uid}."_paused");
}
logsql("Pausing the campaign $camp->{uid}, because we got email in spam folder, mail_list_id: $email");
$redis->set($camp->{uid}."_paused","Is backend, pagautas del spam_checker $email");
}
}

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

sub get_subject {
my ($mail_list_id) = @_;
my $sth = $dbh->prepare("select campaigns.id as campid, campaigns.subject as subject from campaigns_lists_segments inner join campaigns on campaigns_lists_segments.campaign_id = campaigns.id where campaigns_lists_segments.mail_list_id = ?");
$sth->execute($mail_list_id);
my $results = $sth->fetchrow_hashref();
my %returnas = ( campid => $results->{campid}, subject => $results->{subject} );
return %returnas;
}

sub timestamp {
  my $t = localtime;
  return sprintf( "%04d-%02d-%02d_%02d:%02d:%02d",
                  $t->year + 1900, $t->mon + 1, $t->mday,
                  $t->hour, $t->min, $t->sec );
}

sub write_log {
my ($text) = @_;
my $filename = "spam_checker.log";
open(my $fh, '>>', $filename);
print $fh "[" . timestamp() . "]: $text\n";
close $fh;
}

sub _report_err {
my ($erruoras) = @_;
write_log($erruoras);
print $erruoras."\n";
return;
}

sub change_traff {
my ($from, $mail_list_id) = @_;
print "Our message detected from: $from, trying to change tracking information\n";
system("/usr/bin/perl \$HOME/auto_change.pl ".$mail_list_id);
}

sub get_imap {
  my ($host, $port, $user, $pass, $spam, $subjectas, $mail_list_id) = @_;
  my $count = 0;
  my $imap = Mail::IMAPClient->new(
    'User'    => $user,
    'Password'=> $pass,
    'Ssl'     => 1 ) or do { print "IMAP Failure: $@\n"; return; };
    # , 'Port'   => $port
  $imap->Timeout(120);
 print "Connecting as $user $pass\n";
# write_log("Connecting as $user $pass");
if (!defined($user) && !defined($pass)) { return; }
  $imap->Server($host);
  if ($imap->connect) {
  my $show_folders = 0;
  if ($show_folders >0) {
  my @folders = $imap->folders;
  binmode *STDOUT, ':utf8';
  for(@folders){
       print decode('IMAP-UTF-7', $_) ," - folder\n";
  }
  }
  # READ SPAM
  write_log("Examining $spam..");
  print "Examining $spam..\n";
  $imap->examine($spam) or do { _report_err("Could not examine spam folder! ".$imap->LastError); return; };
  write_log("Selecting $spam Folder...");
  print "Selecting $spam Folder...\n";
  $imap->select($spam) or do { _report_err("IMAP Select Error: $! ".$imap->LastError); return; };
  write_log("Getting message lists... ");
  print "Getting message lists... ";
  $count = $imap->message_count($spam);
  if ($count > 0) {
  write_log("$count messages found!");
  print "$count messages found!\n";
  my @msgz = $imap->search('ALL') or do { _report_err('Messages not found'); return; };
  my $detected = 0;
 if (@msgz) {
   foreach my $msg (@msgz) {
     write_log("Reading message $msg");
     print "Reading message $msg\n";
      my $raw = $imap->message_string($msg);
#      my $struct = $imap->get_bodystructure($msg);
#      my $date = $imap->date($msg);
      my $email = Courriel->parse(text => $raw);
      write_log("E-Mail Received, subject ".$email->subject." from: ".$email->from);
      print "E-Mail Received, subject ".$email->subject." from: ".$email->from."\n";
      $imap->see($msg);
      # if this is our message
      $imap->delete_message($msg);
      if ($email->subject eq $subjectas && $detected == 0) {
      $detected = 1;
#      change_traff($email->from,$mail_list_id);
# do campaign pause instead
logsql("Email subject ".$email->subject." from ".$email->from." sits on the spam folder");
campaign_pause($mail_list_id);
      }

     }
write_log("Finished processing $spam");
print "Finished processing $spam\n";
     } else {
     _report_err("INBOX is empty!\n");
     }


} else {
_report_err("Spam folder is empty!");
}
$imap->close($imap);
} else {
_report_err("Unable to connect to imap of $host");
}
}

die "DO NOT RUN AS ROOT THIS SCRIPT!\n" if (`id -u` == "0");

read_lavonelis();
dbconn();
# need thing to foreach all the mail_lists table and find not empty imap_* fields, then foreach them with imap checker :-)
my $sth = $dbh->prepare("SELECT id, imap_host, imap_mail, imap_pass, imap_spam FROM mail_lists where imap_host is not NULL and imap_mail is not NULL and imap_pass is not NULL and imap_spam is not NULL and imap_host != '' and imap_mail != '' and imap_pass != '' and imap_spam != ''" );
$sth->execute();
my $results = $sth->fetchall_arrayref({});
for (@{$results}) {
print "Trying to get listing for: $_->{imap_host} $_->{imap_mail} $_->{imap_pass}\n";
my %got = get_subject($_->{id});
print "Got target subject: ".$got{'subject'}."\n";
get_imap($_->{imap_host},"port", $_->{imap_mail}, $_->{imap_pass},$_->{imap_spam},$got{'subject'},$_->{id});
}

# TODO reikia padaryti toki pati scripta kuris selectintu maillistus ir pagal tai kas virsuj parasyta, generuotu ir siuntinetu kas minute mailus


#get_imap("imap.ziggo.nl","port", "ivo.krijnen\@ziggo.nl", "93269326","Spam",get_subject());
#get_imap("imap.mail.com","port", "monkey47\@mail.com", "noiseredno","Spam",get_subject());
