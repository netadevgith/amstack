package main

import "fmt"

const (
	// Table types
	SQL_TABLE_TYPE_EMAILS  = 1
	SQL_TABLE_TYPE_DOMAINS = 2
	SQL_TABLE_TYPE_NAMES   = 3
	SQL_TABLE_TYPE_MX      = 4
	SQL_TABLE_STORAGE      = 5
	SQL_TABLE_OPENAI       = 6
	SQL_TABLE_CLICKAI      = 7
	SQL_TABLE_DELIVERIES   = 8
)

func InitializeTable(table string, table_type int, force_mode *bool) {
	//	dbconn()
	switch table_type {
	case SQL_TABLE_STORAGE:
		// storage table big_emails
		// FIXME this should be revisited and updated :)
		stmt, err := mysqldb.Prepare(fmt.Sprintf("CREATE Table %s (id int NOT NULL AUTO_INCREMENT, val varchar(191) UNIQUE, reason varchar(191) DEFAULT NULL, created_at timestamp DEFAULT current_timestamp, PRIMARY KEY (id));", settings.SqlStorageTable))
		_, err = stmt.Exec()
		if err != nil {
			if *force_mode {
				// delete the table and try again
				stmtb, err := mysqldb.Prepare(fmt.Sprintf("DROP TABLE %s;", settings.SqlStorageTable))
				_, err = stmtb.Exec()
				if err == nil {
					fmt.Printf("Table %s deleted, ok!\n", settings.SqlStorageTable)
					_, err = stmt.Exec()
					if err == nil {
						fmt.Printf("Table %s creation done!\n", settings.SqlStorageTable)
					}

				}
			} else {
				fmt.Printf("Table %s creation failed: %s\n", settings.SqlStorageTable, err.Error())
				fmt.Printf("You could use the force mode for this by specifying --force parameter, then the table should be deleted\n")
			}
		} else {
			fmt.Printf("Table %s creation done!\n", settings.SqlStorageTable)
		}
		return
	case SQL_TABLE_TYPE_EMAILS:
		// blacklists
		stmt, err := mysqldb.Prepare(fmt.Sprintf("CREATE Table %s (id int NOT NULL AUTO_INCREMENT, val varchar(191) UNIQUE, type int not null DEFAULT %d, reason varchar(191) DEFAULT NULL, created_at timestamp DEFAULT current_timestamp, PRIMARY KEY (id));", settings.SQLBlacklistsTable, BLACKLIST_EMAILS_TYPE_HARDBOUNCE))
		_, err = stmt.Exec()
		if err != nil {
			if *force_mode {
				// delete the table and try again
				stmtb, err := mysqldb.Prepare(fmt.Sprintf("DROP TABLE %s;", settings.SQLBlacklistsTable))
				_, err = stmtb.Exec()
				if err == nil {
					fmt.Printf("Table %s deleted, ok!\n", settings.SQLBlacklistsTable)
					_, err = stmt.Exec()
					if err == nil {
						fmt.Printf("Table %s creation done!\n", settings.SQLBlacklistsTable)
					}

				}
			} else {
				fmt.Printf("Table %s creation failed: %s\n", settings.SQLBlacklistsTable, err.Error())
				fmt.Printf("You could use the force mode for this by specifying --force parameter, then the table should be deleted\n")
			}
		} else {
			fmt.Printf("Table %s creation done!\n", settings.SQLBlacklistsTable)
		}
		return
	case SQL_TABLE_TYPE_DOMAINS:
		// blacklists_domain
		stmt, err := mysqldb.Prepare(fmt.Sprintf("CREATE Table %s (id int NOT NULL AUTO_INCREMENT, val varchar(191) UNIQUE, type int not null DEFAULT %d, reason varchar(191) DEFAULT NULL, created_at timestamp DEFAULT current_timestamp, PRIMARY KEY (id));", settings.SQLBlacklistsDomainTable, BLACKLIST_DOMAINS_TYPE_BLOCKED))
		_, err = stmt.Exec()
		if err != nil {
			if *force_mode {
				// delete the table and try again
				stmtb, err := mysqldb.Prepare(fmt.Sprintf("DROP TABLE %s;", settings.SQLBlacklistsDomainTable))
				_, err = stmtb.Exec()
				if err == nil {
					fmt.Printf("Table %s deleted, ok!\n", settings.SQLBlacklistsDomainTable)
					_, err = stmt.Exec()
					if err == nil {
						fmt.Printf("Table %s creation done!\n", settings.SQLBlacklistsDomainTable)
					}

				}
			} else {
				fmt.Printf("Table %s creation failed: %s\n", settings.SQLBlacklistsDomainTable, err.Error())
				fmt.Printf("You could use the force mode for this by specifying --force parameter, then the table should be deleted\n")
			}
		} else {
			fmt.Printf("Table %s creation done!\n", settings.SQLBlacklistsDomainTable)
		}
		return
	case SQL_TABLE_TYPE_NAMES:
		// blcklists_names
		stmt, err := mysqldb.Prepare(fmt.Sprintf("CREATE Table %s (id int NOT NULL AUTO_INCREMENT, val varchar(191) UNIQUE, type int not null DEFAULT %d, reason varchar(191) DEFAULT NULL, created_at timestamp DEFAULT current_timestamp, PRIMARY KEY (id));", settings.SQLBlacklistsNameTable, BLACKLIST_NAMES_TYPE_BLOCKED))
		_, err = stmt.Exec()
		if err != nil {
			if *force_mode {
				// delete the table and try again
				stmtb, err := mysqldb.Prepare(fmt.Sprintf("DROP TABLE %s;", settings.SQLBlacklistsNameTable))
				_, err = stmtb.Exec()
				if err == nil {
					fmt.Printf("Table %s deleted, ok!\n", settings.SQLBlacklistsNameTable)
					_, err = stmt.Exec()
					if err == nil {
						fmt.Printf("Table %s creation done!\n", settings.SQLBlacklistsNameTable)
					}

				}
			} else {
				fmt.Printf("Table %s creation failed: %s\n", settings.SQLBlacklistsNameTable, err.Error())
				fmt.Printf("You could use the force mode for this by specifying --force parameter, then the table should be deleted\n")
			}
		} else {
			fmt.Printf("Table %s creation done!\n", settings.SQLBlacklistsNameTable)
		}
		return
	case SQL_TABLE_TYPE_MX:
		// blacklists_mx
		stmt, err := mysqldb.Prepare(fmt.Sprintf("CREATE Table %s (id int NOT NULL AUTO_INCREMENT, val varchar(191) UNIQUE, type int not null DEFAULT %d, reason varchar(191) DEFAULT NULL, created_at timestamp DEFAULT current_timestamp, PRIMARY KEY (id));", settings.SQLBlacklistsMxTable, BLACKLIST_MX_TYPE_BLOCKED))
		_, err = stmt.Exec()
		if err != nil {
			if *force_mode {
				// delete the table and try again
				stmtb, err := mysqldb.Prepare(fmt.Sprintf("DROP TABLE %s;", settings.SQLBlacklistsMxTable))
				_, err = stmtb.Exec()
				if err == nil {
					fmt.Printf("Table %s deleted, ok!\n", settings.SQLBlacklistsMxTable)
					_, err = stmt.Exec()
					if err == nil {
						fmt.Printf("Table %s creation done!\n", settings.SQLBlacklistsMxTable)
					}

				}
			} else {
				fmt.Printf("Table %s creation failed: %s\n", settings.SQLBlacklistsMxTable, err.Error())
				fmt.Printf("You could use the force mode for this by specifying --force parameter, then the table should be deleted\n")
			}
		} else {
			fmt.Printf("Table %s creation done!\n", settings.SQLBlacklistsMxTable)
		}
		return
	case SQL_TABLE_OPENAI:
		// opens
		stmt, err := mysqldb.Prepare(fmt.Sprintf("CREATE Table %s (id int NOT NULL AUTO_INCREMENT, email varchar(191) UNIQUE, ip_address varchar(191) DEFAULT NULL, server_ip varchar(191) DEFAULT NULL, server_ptr varchar(191) DEFAULT NULL, user_agent varchar(191) DEFAULT NULL, location varchar(191) DEFAULT NULL, date_added timestamp DEFAULT current_timestamp, mx varchar(191) DEFAULT NULL, deployment varchar(191) DEFAULT NULL, domain varchar(191) DEFAULT NULL, campaign varchar(191) DEFAULT NULL, maillist varchar(191) DEFAULT NULL, PRIMARY KEY (id));", settings.SQLOpenersTable))
		_, err = stmt.Exec()
		if err != nil {
			if *force_mode {
				// delete the table and try again
				stmtb, err := mysqldb.Prepare(fmt.Sprintf("DROP TABLE %s;", settings.SQLOpenersTable))
				_, err = stmtb.Exec()
				if err == nil {
					fmt.Printf("Table %s deleted, ok!\n", settings.SQLOpenersTable)
					_, err = stmt.Exec()
					if err == nil {
						fmt.Printf("Table %s creation done!\n", settings.SQLOpenersTable)
					}

				}
			} else {
				fmt.Printf("Table %s creation failed: %s\n", settings.SQLOpenersTable, err.Error())
				fmt.Printf("You could use the force mode for this by specifying --force parameter, then the table should be deleted\n")
			}
		} else {
			fmt.Printf("Table %s creation done!\n", settings.SQLOpenersTable)
		}
		return
	case SQL_TABLE_CLICKAI:
		// clicks
		stmt, err := mysqldb.Prepare(fmt.Sprintf("CREATE Table %s (id int NOT NULL AUTO_INCREMENT, email varchar(191) UNIQUE, ip_address varchar(191) DEFAULT NULL, server_ip varchar(191) DEFAULT NULL, server_ptr varchar(191) DEFAULT NULL, user_agent varchar(191) DEFAULT NULL, location varchar(191) DEFAULT NULL, date_added timestamp DEFAULT current_timestamp, mx varchar(191) DEFAULT NULL, deployment varchar(191) DEFAULT NULL, domain varchar(191) DEFAULT NULL, campaign varchar(191) DEFAULT NULL, maillist varchar(191) DEFAULT NULL, PRIMARY KEY (id));", settings.SQLClickersTable))
		_, err = stmt.Exec()
		if err != nil {
			if *force_mode {
				// delete the table and try again
				stmtb, err := mysqldb.Prepare(fmt.Sprintf("DROP TABLE %s;", settings.SQLClickersTable))
				_, err = stmtb.Exec()
				if err == nil {
					fmt.Printf("Table %s deleted, ok!\n", settings.SQLClickersTable)
					_, err = stmt.Exec()
					if err == nil {
						fmt.Printf("Table %s creation done!\n", settings.SQLClickersTable)
					}

				}
			} else {
				fmt.Printf("Table %s creation failed: %s\n", settings.SQLClickersTable, err.Error())
				fmt.Printf("You could use the force mode for this by specifying --force parameter, then the table should be deleted\n")
			}
		} else {
			fmt.Printf("Table %s creation done!\n", settings.SQLClickersTable)
		}
		return
	case SQL_TABLE_DELIVERIES:
		// deliveries
		stmt, err := mysqldb.Prepare(fmt.Sprintf("CREATE Table %s (id int NOT NULL AUTO_INCREMENT, email varchar(191) UNIQUE, campaign varchar(191), server_ip varchar(191), mx varchar(191), deployment varchar(191), status varchar(191), date_added timestamp DEFAULT current_timestamp, PRIMARY KEY (id));", settings.SQLDeliveriesTable))
		_, err = stmt.Exec()
		if err != nil {
			if *force_mode {
				// delete the table and try again
				stmtb, err := mysqldb.Prepare(fmt.Sprintf("DROP TABLE %s;", settings.SQLDeliveriesTable))
				_, err = stmtb.Exec()
				if err == nil {
					fmt.Printf("Table %s deleted, ok!\n", settings.SQLDeliveriesTable)
					_, err = stmt.Exec()
					if err == nil {
						fmt.Printf("Table %s creation done!\n", settings.SQLDeliveriesTable)
					}

				}
			} else {
				fmt.Printf("Table %s creation failed: %s\n", settings.SQLDeliveriesTable, err.Error())
				fmt.Printf("You could use the force mode for this by specifying --force parameter, then the table should be deleted\n")
			}
		} else {
			fmt.Printf("Table %s creation done!\n", settings.SQLDeliveriesTable)
		}
		return
	}
}
