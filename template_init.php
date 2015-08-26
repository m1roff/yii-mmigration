<?php
/**
 * Шаблон для инит(главной) миграции
 */
class {ClassName} extends DMigration
{
	private $inPath='{MigrationsPath}';
	private $subMigrations={SubMigrationsList};
	public function up()
	{
		if( !$this->migrateSub($this->inPath, $this->subMigrations) )
		{
			return false;
		}
	}

	public function down()
	{
		if( !$this->migrateDownSub($this->inPath, $this->subMigrations) )
		{
			return false;
		}
	}

}