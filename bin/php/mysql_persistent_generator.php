#!/usr/bin/env php
<?php

include_once( 'kernel/classes/ezscript.php' );
include_once( 'lib/ezutils/classes/ezcli.php' );
include_once( 'lib/ezdb/classes/ezdb.php' );
include_once( 'lib/ezutils/classes/ezphpcreator.php' );

$cli =& eZCLI::instance();

$scriptSettings = array();
$scriptSettings['description'] = 'Persisent class generation for MySQL 5.x';
$scriptSettings['use-session'] = true;
$scriptSettings['use-modules'] = true;
$scriptSettings['use-extensions'] = true;

$script =& eZScript::instance( $scriptSettings );
$script->startup();

$config = '[list:?]';
$argumentConfig = '[TABLENAME][CLASSNAME]';
$optionHelp = array( 'list' => 'Either "tables" or "columns"');
$arguments = false;

$useStandardOptions = true;

$options = $script->getOptions( $config, $argumentConfig, $optionHelp, $arguments, $useStandardOptions );
$script->initialize();

$tableName = false;

$db =& eZDB::instance();

if ( $options['list'] )
{
    $listValue = $options['list'];
    switch ( $listValue )
    {
        case 'tables':
        {
            $tables = $db->arrayQuery( "select table_name
            from information_schema.tables
            where table_type='BASE TABLE'" );

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
                WHERE table_name='$tableName'
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
    WHERE table_name='$tableName'
    order by table_name,ordinal_position" );

    $defArray = array();
    $defArray['fields'] = array();

    $functionAttributes = array();
    $guidFunctions = array();

    foreach ( $result as $column )
    {
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


    $defArray['keys'] = array();
    $defArray['function_attributes'] = $functionAttributes;
    $defArray['increment_key'] = false;
    $defArray['class_name'] = $className;
    $defArray['sort'] = array();
    $defArray['name'] = $tableName;

    $fileName = strtolower( $className ) . '.php';

    $php = new eZPHPCreator( 'var/cache/phpcreator', $fileName );
    $php->addComment( 'Persistent object class auto-generated' );
    $php->addSpace();

    $php->addInclude( 'kernel/classes/ezpersistentobject.php' );
    $php->addSpace();

    // Class start
    $php->addCodePiece( "class $className extends eZPersistentObject\n" );
    $php->addCodePiece( "{\n" );

    // Constructor
    $php->addCodePiece( "function $className( \$row )\n", array( 'spacing' => 4 ) );
    $php->addCodePiece( "{\n", array( 'spacing' => 4 ) );
    $php->addMethodCall( 'this', 'eZPersistentObject', array( array( 'row', EZ_PHPCREATOR_METHOD_CALL_PARAMETER_VARIABLE ) ), false, array( 'spacing' => 8 ) );
    $php->addCodePiece( "}\n", array( 'spacing' => 4 ) );
    $php->addSpace();

    // Persistent object definition
    $php->addCodePiece( "function definition()\n", array( 'spacing' => 4 ) );
    $php->addCodePiece( "{\n", array( 'spacing' => 4 ) );
    $php->addVariable( 'def', $defArray, EZ_PHPCREATOR_VARIABLE_ASSIGNMENT, array( 'spacing' => 8 ) );
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