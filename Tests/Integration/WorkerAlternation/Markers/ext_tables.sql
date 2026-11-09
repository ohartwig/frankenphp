CREATE TABLE tx_workeralternationmarkers_greeting (
    title varchar(255) DEFAULT '' NOT NULL
);

CREATE TABLE tx_workeralternationmarkers_submission (
    form varchar(255) DEFAULT '' NOT NULL,
    name varchar(255) DEFAULT '' NOT NULL,
    email varchar(255) DEFAULT '' NOT NULL,
    topic varchar(255) DEFAULT '' NOT NULL,
    message text,
    site varchar(255) DEFAULT '' NOT NULL,
    language int(11) DEFAULT '-1' NOT NULL,
    host varchar(255) DEFAULT '' NOT NULL
);
