#!/usr/bin/perl -w
# v1.0
# tracking address autochanger

# go analogas: http://p.9g.lt/view.php?paste=89f5e6307942c62f98fee8c7e3de3b81
use strict;
use warnings;
use threads;
use threads::shared;
use Thread::Queue;
use File::Basename;
use Time::HiRes qw(usleep nanosleep gettimeofday);
use JSON::XS 'decode_json';
use Data::Uniqid qw ( suniqid uniqid luniqid );
use MIME::Base64 qw( encode_base64 );
use Data::Dumper;
use DBI;
use Time::localtime;

my $rand_file = "trackinginfo.txt";
my %env; # variable to put laravel settings in
my $dbh;
##################

my $DEBUG = 0;
my $sleep = usleep(1);

my %settings = ();

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

sub timestamp {
  my $t = localtime;
  return sprintf( "%04d-%02d-%02d_%02d:%02d:%02d",
                  $t->year + 1900, $t->mon + 1, $t->mday,
                  $t->hour, $t->min, $t->sec );
}

sub write_log {
my ($text) = @_;
my $filename = 'trackinglog.txt';
open(my $fh, '>>', $filename);
print $fh "[" . timestamp() . "]: $text\n";
close $fh;
}


sub get_settings {
dbconn();
my $sth = $dbh->prepare( "SELECT pavad, reiksm FROM nustatymai" );  
$sth->execute();
my $results = $sth->fetchall_arrayref({});
my @switch_ports = ();
for (@{$results}) {
   my $name = $_->{pavad};
   my $value = $_->{reiksm};
push @{ $settings{$name} }, $value;
}
#print Dumper(%settings);
#print "Testas: ".$settings{url_web_view}[0]."\n";
$sth->finish();
#$dbh->disconnect();
}

sub set_mysql {
my ($trackdom,$reply,$mail_list_id) = @_;
print "Changing domain to: $trackdom and reply addr: $reply\n";
if ($mail_list_id eq 0) {
print "Global changer issued...\n";
my $sth = $dbh->prepare("UPDATE nustatymai set reiksm = ? where pavad = 'trackurl'");
$sth->execute($trackdom);
my $stha = $dbh->prepare("UPDATE campaigns set from_email = ?, reply_to = ?");
$stha->execute($reply,$reply);
my $sthb = $dbh->prepare("UPDATE settings SET value = REPLACE(value, ?, ?) WHERE value LIKE ?");
$sthb->execute($settings{trackurl}[0],$trackdom,"%".$settings{trackurl}[0]."%");
} else {
print "Change per campaign issued...\n";
# do changes on only based on the originating maillist, not the global system
my $sthb = $dbh->prepare("select campaigns.id as campid, campaigns.subject as subject from campaigns_lists_segments inner join campaigns on campaigns_lists_segments.campaign_id = campaigns.id where campaigns_lists_segments.mail_list_id = ?");
$sthb->execute($mail_list_id);
my $results = $sthb->fetchall_arrayref({});
my $sthc = $dbh->prepare("select id, html, plain from campaigns where id = ?");
$sthc->execute(@$results[0]->{campid}); # get data
my $rez = $sthc->fetchall_arrayref({});
my $html = @$rez[0]->{html};
my $plain = @$rez[0]->{plain};
# REGEN HTML AND PLAIN
$html =~ s/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,5}(\:[0-9]+)?\/?/http:\/\/$trackdom\//g;
$plain =~ s/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,5}(\:[0-9]+)?\/?/http:\/\/$trackdom\//g;
# Post values then
my $sthd = $dbh->prepare("UPDATE campaigns set from_email = ?, reply_to = ?, html = ?, plain = ? WHERE id = ?");
$sthd->execute($reply,$reply,$html,$plain,@$results[0]->{campid});
}
write_log("Old: ".$settings{trackurl}[0]." changed to: ".$trackdom." reply to: ".$reply);
}


# DEPRECATED, now autosender sends the changed mail on the fly 2018.03.19
sub change_parm {
my ($trackdom,$reply,$mail_list_id) = @_;
my $PARM=`find public_html/storage/app/keys/ -name \*.parm -type f -printf '%T@ %p\n' | sort -n | tail -1 | cut -f2- -d" "`;
chomp($PARM);
my $com;
open(my $fh, '<:encoding(UTF-8)', $PARM) or return;
while (my $command = <$fh>) {
chomp $command;
$com = $command;
}
close($fh);
my @comarr = split /::/, $com;
# rewrite the file
open(my $fc, '>', $PARM) or return;
#print "\$HOME/gosender $reply::$comarr[1]::$comarr[2]::$reply::$comarr[4]::$comarr[5]::$comarr[6]::$comarr[7]::$comarr[8]::$trackdom\n";
print $fc "\$HOME/gosender $reply::$comarr[1]::$comarr[2]::$reply::$comarr[4]::$comarr[5]::$comarr[6]::$comarr[7]::$comarr[8]::$trackdom\n";
close $fc;
}
########################

sub change_tracking_info {
my ($mail_list_id) = @_;
open FILE, "<$rand_file" or die "Could not open $rand_file: $!\n";
my @array=<FILE>;
close FILE;
my $randomline=$array[rand @array];
chomp($randomline);
my @track = split /:/, $randomline;
# check if next item is not a current
if ($settings{trackurl}[0] ne $track[0]) {
set_mysql($track[0],$track[1],$mail_list_id); 
#change_parm($track[0],$track[1],$mail_list_id); # DEPRECATED
} else {
change_tracking_info($mail_list_id);
}
}

read_lavonelis();
dbconn();
get_settings();
print "Current: ".$settings{trackurl}[0]."\n";
# now we will pase the maillist id with the parameters
if (defined($ARGV[0])) {
print "Mail list id defined: $ARGV[0]\n";
change_tracking_info($ARGV[0]);
} else {
change_tracking_info(0);
}
