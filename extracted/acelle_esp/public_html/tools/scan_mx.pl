#!/usr/bin/perl -w
use Term::ProgressBar;
use strict;
use warnings;
use Net::DNS;
use DBI;
my %env;
my $dbh;
$env{DB_DATABASE} = "trackingas";
$env{DB_HOST} = "62.210.13.211";
$env{DB_USERNAME} = "ses_remote";
$env{DB_PASSWORD} = "bGh9CaF897q";
sub dbconn {
$dbh = DBI->connect("DBI:mysql:".$env{DB_DATABASE}.":".$env{DB_HOST}, $env{DB_USERNAME}, $env{DB_PASSWORD} ,{ PrintError => 1, RaiseError => 0 }) or die "Unable to connect to MySQL\n";
}





sub process_domain {
my ($id,$domain,$table) = @_;
#my $out = `host -a $domain|egrep "IN.*MX"`;
#print $out."\n";
my $res = Net::DNS::Resolver->new;
my @mx = mx($res, $domain) or return;
my $hosts = "";
foreach my $record (@mx) {
#print $record->exchange."\n";
 $hosts = $hosts . $record->exchange."\n";
 }
# update sql
#print $hosts;
my $stha = $dbh->prepare("UPDATE $table set mx = ? where id = ?");
$stha->execute($hosts,$id);
}



dbconn();

sub process_table {
my ($table) = @_;
# app openai
my $sth = $dbh->prepare("SELECT id,email from $table where mx is NULL order by id desc");
$sth->execute();
my $total = $sth->rows;
my $arr = $sth->fetchall_arrayref({});
my $count = 0;
my $progress = Term::ProgressBar->new ({name => 'Looking for DNS records', count => $total, ETA => 'linear'});
for my $list (@$arr) {
$count++;
next unless ($list->{email} =~ /[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,7}/);
$progress->update($count);
my @domain = split(/\@/, $list->{email});
#print "Processing $list->{email} current domain: $domain[1]\n";
process_domain($list->{id},$domain[1],$table);
}
}

process_table("app_openai");
process_table("app_openai_nl");
