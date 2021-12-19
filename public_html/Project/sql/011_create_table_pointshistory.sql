CREATE TABLE IF NOT EXISTS Point_History(
    -- this will be like the bank project transactions table (pairs of transactions)
    id int AUTO_INCREMENT PRIMARY KEY ,
    user_id int,
    point_change int,
    reason varchar(15) not null COMMENT 'The type of transaction that occurred',
    created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    constraint ZeroTransferNotAllowed CHECK(point_change != 0)
)