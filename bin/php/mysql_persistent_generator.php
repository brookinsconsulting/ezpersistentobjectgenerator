#!/usr/bin/env php
<?php

require 'autoload.php';

if ( !function_exists( 'readline' ) )
{
    function readline( $prompt = '' )
    {
        echo $prompt . ' ';
        return trim( fgets( STDIN ) );
    }
}

$cli = eZCLI::instance();

$scriptSettings = array();
$scriptSettings['description'] = 'Persisent class generation for MySQL 5.x';
$scriptSettings['use-session'] = true;
$scriptSettings['use-modules'] = true;
$scriptSettings['use-extensions'] = true;

$script = eZScript::instance( $scriptSettings );
$script->startup();

$config = '[list:?]';
$argumentConfig = '[TABLENAME][CLASSNAME]';
$optionHelp = array( 'list' => 'Either "tables" or "columns"');
$arguments = false;

$useStandardOptions = true;

$options = $script->getOptions( $config, $argumentConfig, $optionHelp, $arguments, $useStandardOptions );
$script->initialize();

$tableName = false;

$db = eZDB::instance();

if ( $options['list'] )
{
    $listValue = $options['list'];
    switch ( $listValue )
    {
        case 'tables':
        {
            $tables = $db->arrayQuery( "select table_name
            from information_schema.tables
            where table_schema='" . $db->escapeString( $db->DB ) . "'
            and table_type='BASE TABLE'" );

            foreach ( $tables as $table )
            {
                $cli->output( $table['table_name'] );
            }
        } break;

        case 'columns':
        {
            if ( count( $options['arguments'] ) < 1 )
            {
                $script->shutdown( 2, 'You need to specify a table name if you want to show a list of columns' );
            }

            $tableName = $options['arguments'][0];

            $columns = $db->arrayQuery( "select COLUMN_NAME, DATA_TYPE
                from information_schema.columns
                where table_schema='" . $db->escapeString( $db->DB ) . "'
                and table_name='" . $db->escapeString( $tableName ) . "'
                order by table_name,ordinal_position" );

            foreach ( $columns as $column )
            {
                $cli->output( $column['COLUMN_NAME'] . ' (' . $column['DATA_TYPE'] . ')' );
            }
        } break;

        default:
        {
            $script->shutdown( 1, 'Unknown list type' );
        }
    }
}
else
{
    $argCount = count( $options['arguments'] );
    if ( $argCount < 1 )
    {
        $script->shutdown( 1, 'wrong argument count' );
    }

    $tableName = $options['arguments'][0];
    if ( $argCount > 1 )
    {
        $className = $options['arguments'][1];
    }
    else
    {
        $className = $tableName;
    }

    $result = $db->arrayQuery( "select COLUMN_NAME, DATA_TYPE, IS_NULLABLE
    from information_schema.columns
    where table_schema='" . $db->escapeString( $db->DB ) . "'
    and table_name='" . $db->escapeString( $tableName ) . "'
    order by table_name,ordinal_position" );

    $defArray = array();
    $defArray['fields'] = array();

    $functionAttributes = array();
    $guidFunctions = array();
    $columnNames = array();

    foreach ( $result as $column )
    {
        $columnNames[] = $column['COLUMN_NAME'];
        $columnDef = array();
        $columnDef['name'] = $column['COLUMN_NAME'];
        $datatype = 'string';
        $default = false;
        switch ( $column['DATA_TYPE'] )
        {
            case 'varchar':
            {
                $datatype = 'string';
            } break;

            case 'int':
            case 'smallint':
            case 'tinyint':
            {
                $datatype = 'integer';
            } break;

            case 'float':
            {
                $datatype = 'float';
            } break;

            case 'longtext':
            {
                $datatype = 'text';
            } break;

            default:
            {
                $cli->output( 'Unknown column type, using default "string": ' . $column['DATA_TYPE'] );
            }
        }

        $columnDef['datatype'] = $datatype;

        $columnDef['required'] = !(bool)$column['IS_NULLABLE'];
        $defArray['fields'][$column['COLUMN_NAME']] = $columnDef;
    }

    $incrementKey = false;
    while ( true )
    {
        $userInput = readline( 'Specify the column to use as increment_key (leave empty to continue): ' );
        if ( $userInput == '' )
        {
            break;
        }

        if ( !in_array( $userInput, $columnNames ) )
        {
            $cli->error( 'Uknown column name: ' . $userInput . '. Choose one of: ' . implode( ', ', $columnNames ) );
        }
        else
        {
            $incrementKey = $userInput;
            break;
        }
    }

    $keys = array();
    while ( true )
    {
        $userInput = readline( 'Which columns to use as key? Specify one at a time (leave empty to continue): ' );
        if ( $userInput == '' )
        {
            break;
        }

        if ( !in_array( $userInput, $columnNames ) )
        {
            $cli->error( 'Uknown column name: ' . $userInput . '. Choose one of: ' . implode( ', ', $columnNames ) );
        }
        else
        {
            $keys[] = $userInput;
        }
    }

    $keys = array_unique( $keys );

    $defArray['keys'] = $keys;
    $defArray['function_attributes'] = $functionAttributes;
    $defArray['increment_key'] = $incrementKey;
    $defArray['class_name'] = $className;
    $defArray['sort'] = array();
    $defArray['name'] = $tableName;

    $fileName = strtolower( $className ) . '.php';

    $php = new eZPHPCreator( 'var/cache/phpcreator', $fileName );
    $php->addComment( 'Persistent object class auto-generated' );
    $php->addSpace();

    // Class start
    $php->addCodePiece( "class $className extends eZPersistentObject\n" );
    $php->addCodePiece( "{\n" );

    // Constructor
    $php->addCodePiece( "function __construct( \$row )\n", array( 'spacing' => 4 ) );
    $php->addCodePiece( "{\n", array( 'spacing' => 4 ) );
    $php->addMethodCall( 'this', 'eZPersistentObject', array( array( 'row', eZPHPCreator::METHOD_CALL_PARAMETER_VARIABLE ) ), false, array( 'spacing' => 8 ) );
    $php->addCodePiece( "}\n", array( 'spacing' => 4 ) );
    $php->addSpace();

    // Persistent object definition
    $php->addCodePiece( "static function definition()\n", array( 'spacing' => 4 ) );
    $php->addCodePiece( "{\n", array( 'spacing' => 4 ) );
    $php->addVariable( 'def', $defArray, eZPHPCreator::VARIABLE_ASSIGNMENT, array( 'spacing' => 8 ) );
    $php->addCodePiece( "return \$def;\n", array( 'spacing' => 8 ) );
    $php->addCodePiece( "}\n", array( 'spacing' => 4 ) );
    $php->addSpace();

    // Class end
    $php->addCodePiece( "}\n" );

    $php->store();

    // let's try out the newly defined class
    include_once( 'var/cache/phpcreator/' . $fileName );

    $objectList = eZPersistentObject::fetchObjectList( call_user_func( array( $className, 'definition' ) ), null, null, null, array( 'offset' => 0, 'limit' => 10 ) );

    eZDebug::writeDebug( 'result count: ' . count( $objectList ) );

    foreach ( $objectList as $object )
    {
        foreach ( $object->attributes() as $attributeName )
        {
            $cli->output( $attributeName . ': ' . $object->attribute( $attributeName ) );
        }

        $cli->output( str_repeat( '_', 10 ) );
    }
}

$script->shutdown( 0 );

?>
