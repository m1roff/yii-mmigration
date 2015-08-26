# MMigration

Extends default Yii`s migration by adding submigrations to the main tree of migrations.

This migration will create new table for migrations with own structure.

## Install

### Copy

Copy/Clone content of the repo to folder "components" of your main application

Ex:

From the root directory of your application 
```
git clone git@github.com:mirkhamidov/yii-mmigration.git ./protected/components/MMigration
```

### Configuration

add to main config

```php
...
'commandMap'=>array(
    'migrate'=>array(
        // REQUIRED. correct alias to the MMigration command
        'class'             => 'application.components.MMigration.MMigrateCommand',
        // REQUIRED. Set an existing alias for main migrations
        'migrationPath'     => 'application.migrations',
        // Migration table name
        'migrationTable'    => 'main_migration',
        // REQUIRED main migrations template. Its different than default one.
        'templateFile'      => 'application.components.MMigration.template',
        // REQUIRED sub migrations template. Structure as default migration
        'templateInitFile'  => 'application.components.MMigration.template_init',
    ),
),
...
```


## Usage

1. Create sub migrations

```bash
./protected/yiic migrate create --migrationPath=<alias to the path of submigration> <name of submigraiont>
```
It can be more than one sub migration in the same "migrationPath"

2. Creating main migration for sub migration

It automaticly adds "init_" prefix to the name of main submigration file

Default name is a path of alias with "init_" prefix.

```bash
./protected/yiic migrate createInit <alias to the path where submigrations located> [--name=<name of main migration>]
```
