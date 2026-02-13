package main

const (
	BLACKLIST_EMAILS_TYPE_HARDBOUNCE     = 1
	BLACKLIST_EMAILS_TYPE_COMPLAINS      = 2
	BLACKLIST_EMAILS_TYPE_ABUSE_REPORT   = 3
	BLACKLIST_EMAILS_TYPE_FEEDBACKLOOP   = 4
	BLACKLIST_EMAILS_TYPE_SPAMTRAP       = 5
	BLACKLIST_DOMAINS_TYPE_DNS_NOT_FOUND = 6
	BLACKLIST_DOMAINS_TYPE_BLOCKED       = 7
	BLACKLIST_DOMAINS_TYPE_SPAMTRAP      = 8
	BLACKLIST_NAMES_TYPE_BLOCKED         = 9
	BLACKLIST_MX_TYPE_DNS_NOT_FOUND      = 10
	BLACKLIST_MX_TYPE_BLOCKED            = 11
	BLACKLIST_MX_TYPE_SPAMTRAP           = 12
	OPENERS_TYPE                         = 20
	CLICKERS_TYPE                        = 21
	DELIVERED_TYPE                       = 30
	BLACKLIST_EMAILS_DEL                 = -1
	BLACKLIST_DOMAINS_DEL                = -2
	BLACKLIST_NAMES_DEL                  = -3
	BLACKLIST_MX_DEL                     = -4
	BLACKLIST_SHOW_RECORDS               = -5
	CACHELIST_EMAILSBL_COUNT             = "emailsbl_count"
	CACHELIST_DOMAINSBL_COUNT            = "domainsbl_count"
	CACHELIST_NAMESBL_COUNT              = "namebl_count"
	CACHELIST_MXBL_COUNT                 = "mxbl_count"
	CACHELIST_OPENERS_COUNT              = "openers_count"
	CACHELIST_CLICKERS_COUNT             = "clickers_count"
	CACHELIST_DELIVERIES_COUNT           = "deliveries_count"
	CACHELIST_STORAGE_COUNT              = "storage_provider_count"
)

type RedisBlacklistItem struct {
	Email string `json:"email"`
	Id    string `json:"id"`
}

func ReturnBlItem(id string, email string) RedisBlacklistItem {
	obj := RedisBlacklistItem{}
	obj.Id = id
	obj.Email = email
	return obj
}

type SQLBlacklistItem struct {
	Val    string `json:"val"`
	Type   int    `json:"type"`
	Reason string `json:"reason"`
}

func ReturnCacheTableByOrigins(id int) string {
	var table string
	switch id {
	case BLACKLIST_EMAILS_DEL:
		table = CACHELIST_EMAILSBL_COUNT
	case BLACKLIST_DOMAINS_DEL:
		table = CACHELIST_DOMAINSBL_COUNT
	case BLACKLIST_NAMES_DEL:
		table = CACHELIST_NAMESBL_COUNT
	case BLACKLIST_MX_DEL:
		table = CACHELIST_MXBL_COUNT
	case BLACKLIST_EMAILS_TYPE_HARDBOUNCE:
		table = CACHELIST_EMAILSBL_COUNT
	case BLACKLIST_EMAILS_TYPE_COMPLAINS:
		table = CACHELIST_EMAILSBL_COUNT
	case BLACKLIST_EMAILS_TYPE_ABUSE_REPORT:
		table = CACHELIST_EMAILSBL_COUNT
	case BLACKLIST_EMAILS_TYPE_FEEDBACKLOOP:
		table = CACHELIST_EMAILSBL_COUNT
	case BLACKLIST_EMAILS_TYPE_SPAMTRAP:
		table = CACHELIST_EMAILSBL_COUNT
	case BLACKLIST_DOMAINS_TYPE_DNS_NOT_FOUND:
		table = CACHELIST_DOMAINSBL_COUNT
	case BLACKLIST_DOMAINS_TYPE_BLOCKED:
		table = CACHELIST_DOMAINSBL_COUNT
	case BLACKLIST_DOMAINS_TYPE_SPAMTRAP:
		table = CACHELIST_DOMAINSBL_COUNT
	case BLACKLIST_NAMES_TYPE_BLOCKED:
		table = CACHELIST_DOMAINSBL_COUNT
	case BLACKLIST_MX_TYPE_DNS_NOT_FOUND:
		table = CACHELIST_MXBL_COUNT
	case BLACKLIST_MX_TYPE_BLOCKED:
		table = CACHELIST_MXBL_COUNT
	case BLACKLIST_MX_TYPE_SPAMTRAP:
		table = CACHELIST_MXBL_COUNT
	case OPENERS_TYPE:
		table = CACHELIST_OPENERS_COUNT
	case CLICKERS_TYPE:
		table = CACHELIST_CLICKERS_COUNT
	case DELIVERED_TYPE:
		table = CACHELIST_DELIVERIES_COUNT
	}
	return ReturnRDTable(table)
}

func ReturnSqlTableByTypeID(id int) string {
	var table string
	switch id {
	case BLACKLIST_EMAILS_DEL:
		table = settings.SQLBlacklistsTable
	case BLACKLIST_DOMAINS_DEL:
		table = settings.SQLBlacklistsDomainTable
	case BLACKLIST_NAMES_DEL:
		table = settings.SQLBlacklistsNameTable
	case BLACKLIST_MX_DEL:
		table = settings.SQLBlacklistsMxTable
	case BLACKLIST_EMAILS_TYPE_HARDBOUNCE:
		table = settings.SQLBlacklistsTable
	case BLACKLIST_EMAILS_TYPE_COMPLAINS:
		table = settings.SQLBlacklistsTable
	case BLACKLIST_EMAILS_TYPE_ABUSE_REPORT:
		table = settings.SQLBlacklistsTable
	case BLACKLIST_EMAILS_TYPE_FEEDBACKLOOP:
		table = settings.SQLBlacklistsTable
	case BLACKLIST_EMAILS_TYPE_SPAMTRAP:
		table = settings.SQLBlacklistsTable
	case BLACKLIST_DOMAINS_TYPE_DNS_NOT_FOUND:
		table = settings.SQLBlacklistsDomainTable
	case BLACKLIST_DOMAINS_TYPE_BLOCKED:
		table = settings.SQLBlacklistsDomainTable
	case BLACKLIST_DOMAINS_TYPE_SPAMTRAP:
		table = settings.SQLBlacklistsDomainTable
	case BLACKLIST_NAMES_TYPE_BLOCKED:
		table = settings.SQLBlacklistsNameTable
	case BLACKLIST_MX_TYPE_DNS_NOT_FOUND:
		table = settings.SQLBlacklistsMxTable
	case BLACKLIST_MX_TYPE_BLOCKED:
		table = settings.SQLBlacklistsMxTable
	case BLACKLIST_MX_TYPE_SPAMTRAP:
		table = settings.SQLBlacklistsMxTable
	case OPENERS_TYPE:
		table = settings.SQLOpenersTable
	case CLICKERS_TYPE:
		table = settings.SQLClickersTable
	case DELIVERED_TYPE:
		table = settings.SQLDeliveriesTable
	default:
		table = settings.SQLBlacklistsTable
	}

	return table
}

func ReturnBlTableByTypeID(id int) (tablea string, queue_tablea string) {
	var table string
	var queue_table string
	switch id {
	case BLACKLIST_EMAILS_DEL:
		table = settings.RedisBlacklistsEmailsTable
		queue_table = table + "_queue"
	case BLACKLIST_DOMAINS_DEL:
		table = settings.RedisBlacklistsDomainTable
		queue_table = table + "_queue"
	case BLACKLIST_NAMES_DEL:
		table = settings.RedisBlacklistsNameTable
		queue_table = table + "_queue"
	case BLACKLIST_MX_DEL:
		table = settings.RedisBlacklistsMXTable
		queue_table = table + "_queue"
	case BLACKLIST_EMAILS_TYPE_HARDBOUNCE:
		table = settings.RedisBlacklistsEmailsTable
		queue_table = settings.RedisBlacklistsEmailsTable + "_queue"
	case BLACKLIST_EMAILS_TYPE_COMPLAINS:
		table = settings.RedisBlacklistsEmailsTable
		queue_table = settings.RedisBlacklistsEmailsTable + "_queue"
	case BLACKLIST_EMAILS_TYPE_ABUSE_REPORT:
		table = settings.RedisBlacklistsEmailsTable
		queue_table = settings.RedisBlacklistsEmailsTable + "_queue"
	case BLACKLIST_EMAILS_TYPE_FEEDBACKLOOP:
		table = settings.RedisBlacklistsEmailsTable
		queue_table = settings.RedisBlacklistsEmailsTable + "_queue"
	case BLACKLIST_EMAILS_TYPE_SPAMTRAP:
		table = settings.RedisBlacklistsEmailsTable
		queue_table = settings.RedisBlacklistsEmailsTable + "_queue"
	case BLACKLIST_DOMAINS_TYPE_DNS_NOT_FOUND:
		table = settings.RedisBlacklistsDomainTable
		queue_table = settings.RedisBlacklistsDomainTable + "_queue"
	case BLACKLIST_DOMAINS_TYPE_BLOCKED:
		table = settings.RedisBlacklistsDomainTable
		queue_table = settings.RedisBlacklistsDomainTable + "_queue"
	case BLACKLIST_DOMAINS_TYPE_SPAMTRAP:
		table = settings.RedisBlacklistsDomainTable
		queue_table = settings.RedisBlacklistsDomainTable + "_queue"
	case BLACKLIST_NAMES_TYPE_BLOCKED:
		table = settings.RedisBlacklistsNameTable
		queue_table = settings.RedisBlacklistsNameTable + "_queue"
	case BLACKLIST_MX_TYPE_DNS_NOT_FOUND:
		table = settings.RedisBlacklistsMXTable
		queue_table = settings.RedisBlacklistsMXTable + "_queue"
	case BLACKLIST_MX_TYPE_BLOCKED:
		table = settings.RedisBlacklistsMXTable
		queue_table = settings.RedisBlacklistsMXTable + "_queue"
	case BLACKLIST_MX_TYPE_SPAMTRAP:
		table = settings.RedisBlacklistsMXTable
		queue_table = settings.RedisBlacklistsMXTable + "_queue"
	case OPENERS_TYPE:
		table = settings.RedisOpenersTable
		queue_table = settings.RedisOpenersTable + "_queue"
	case CLICKERS_TYPE:
		table = settings.RedisClickersTable
		queue_table = settings.RedisClickersTable + "_queue"
	case DELIVERED_TYPE:
		table = settings.RedisDeliveryTable
		queue_table = settings.RedisDeliveryTable + "_queue"
	default:
		table = settings.RedisBlacklistsEmailsTable
		queue_table = settings.RedisBlacklistsEmailsTable + "_queue"
	}
	return table, queue_table
}

type Opener struct {
	Email      string `json:"email"`
	Ip_address string `json:"ip_address"`
	Server_ip  string `json:"server_ip"`
	Server_ptr string `json:"server_ptr"`
	User_agent string `json:"user_agent"`
	Location   string `json:"location"`
	Date_added string `json:"date_added"`
	Mx         string `json:"mx"`
	Deployment string `json:"deployment"`
	Domain     string `json:"domain"`
	Campaign   string `json:"campaign"`
	Maillist   string `json:"maillist"`
}

type Clicker struct {
	Email      string `json:"email"`
	Ip_address string `json:"ip_address"`
	Server_ip  string `json:"server_ip"`
	Server_ptr string `json:"server_ptr"`
	User_agent string `json:"user_agent"`
	Location   string `json:"location"`
	Date_added string `json:"date_added"`
	Mx         string `json:"mx"`
	Deployment string `json:"deployment"`
	Domain     string `json:"domain"`
	Campaign   string `json:"campaign"`
	Maillist   string `json:"maillist"`
}

type Delivery struct {
	Email      string `json:"email"`
	Campaign   string `json:"campaign"`
	Server_ip  string `json:"server_ip"`
	Mx         string `json:"mx"`
	Deployment string `json:"deployment"`
	Status     string `json:"status"`
	Date_added string `json:"date_added"`
}
