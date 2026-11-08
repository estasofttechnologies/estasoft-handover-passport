<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Data\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The tables must be created on MySQL and MariaDB, not only on the database the tests happen to use.
 */
final class SchemaTest extends TestCase {

	/**
	 * Words MySQL 8 reserves (MariaDB reserves most of the same ones). A column with one of these names needs backticks.
	 */
	private const RESERVED = 'ACCESSIBLE ADD ALL ALTER ANALYZE AND AS ASC ASENSITIVE BEFORE BETWEEN BIGINT BINARY BLOB BOTH BY CALL CASCADE CASE CHANGE CHAR CHARACTER CHECK COLLATE COLUMN CONDITION CONSTRAINT CONTINUE CONVERT CREATE CROSS CUBE CUME_DIST CURRENT_DATE CURRENT_TIME CURRENT_TIMESTAMP CURRENT_USER CURSOR DATABASE DATABASES DAY_HOUR DAY_MICROSECOND DAY_MINUTE DAY_SECOND DEC DECIMAL DECLARE DEFAULT DELAYED DELETE DENSE_RANK DESC DESCRIBE DETERMINISTIC DISTINCT DISTINCTROW DIV DOUBLE DROP DUAL EACH ELSE ELSEIF EMPTY ENCLOSED ESCAPED EXCEPT EXISTS EXIT EXPLAIN FALSE FETCH FIRST_VALUE FLOAT FLOAT4 FLOAT8 FOR FORCE FOREIGN FROM FULLTEXT FUNCTION GENERATED GET GRANT GROUP GROUPING GROUPS HAVING HIGH_PRIORITY HOUR_MICROSECOND HOUR_MINUTE HOUR_SECOND IF IGNORE IN INDEX INFILE INNER INOUT INSENSITIVE INSERT INT INT1 INT2 INT3 INT4 INT8 INTEGER INTERSECT INTERVAL INTO IO_AFTER_GTIDS IO_BEFORE_GTIDS IS ITERATE JOIN JSON_TABLE KEY KEYS KILL LAG LAST_VALUE LATERAL LEAD LEADING LEAVE LEFT LIKE LIMIT LINEAR LINES LOAD LOCALTIME LOCALTIMESTAMP LOCK LONG LONGBLOB LONGTEXT LOOP LOW_PRIORITY MASTER_BIND MASTER_SSL_VERIFY_SERVER_CERT MATCH MAXVALUE MEDIUMBLOB MEDIUMINT MEDIUMTEXT MIDDLEINT MINUTE_MICROSECOND MINUTE_SECOND MOD MODIFIES NATURAL NOT NO_WRITE_TO_BINLOG NTH_VALUE NTILE NULL NUMERIC OF ON OPTIMIZE OPTIMIZER_COSTS OPTION OPTIONALLY OR ORDER OUT OUTER OUTFILE OVER PARTITION PERCENT_RANK PRECISION PRIMARY PROCEDURE PURGE RANGE RANK READ READS READ_WRITE REAL RECURSIVE REFERENCES REGEXP RELEASE RENAME REPEAT REPLACE REQUIRE RESIGNAL RESTRICT RETURN REVOKE RIGHT RLIKE ROW ROWS ROW_NUMBER SCHEMA SCHEMAS SECOND_MICROSECOND SELECT SENSITIVE SEPARATOR SET SHOW SIGNAL SMALLINT SPATIAL SPECIFIC SQL SQLEXCEPTION SQLSTATE SQLWARNING SQL_BIG_RESULT SQL_CALC_FOUND_ROWS SQL_SMALL_RESULT SSL STARTING STORED STRAIGHT_JOIN SYSTEM TABLE TERMINATED THEN TINYBLOB TINYINT TINYTEXT TO TRAILING TRIGGER TRUE UNDO UNION UNIQUE UNLOCK UNSIGNED UPDATE USAGE USE USING UTC_DATE UTC_TIME UTC_TIMESTAMP VALUES VARBINARY VARCHAR VARCHARACTER VARYING VIRTUAL WHEN WHERE WHILE WINDOW WITH WRITE XOR YEAR_MONTH ZEROFILL';

	protected function setUp(): void {
		$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_no_column_is_named_with_a_reserved_word_unless_it_is_quoted(): void {
		$reserved = array_flip( explode( ' ', self::RESERVED ) );
		$checked  = 0;
		foreach ( Schema::statements( 'DEFAULT CHARACTER SET utf8mb4' ) as $sql ) {
			foreach ( explode( "\n", $sql ) as $line ) {
				if ( 1 !== preg_match( '/^  (`?)([a-z_0-9]+)`?\s+(?:bigint|int|tinyint|varchar|longtext|text|date|datetime|decimal|char)\b/i', $line, $m ) ) {
					continue;
				}
				++$checked;
				if ( '`' === $m[1] ) {
					continue;
				}
				$this->assertArrayNotHasKey( strtoupper( $m[2] ), $reserved, 'The column "' . $m[2] . '" is a reserved word in MySQL; put backticks around it in the schema and in every query.' );
			}
		}
		$this->assertGreaterThan( 100, $checked, 'The test should have looked at every column.' );
	}

	public function test_a_reserved_name_in_the_schema_is_quoted(): void {
		$all = implode( "\n", Schema::statements( '' ) );
		$this->assertStringContainsString( '`sensitive` tinyint(1)', $all );
		$this->assertStringNotContainsString( "\n  sensitive ", $all );
	}
}
