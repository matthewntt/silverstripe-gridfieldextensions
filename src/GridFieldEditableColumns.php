<?php

namespace SilverStripe\GridFieldExtensions;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Object;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Forms\GridField\GridField_SaveHandler;
use SilverStripe\Forms\GridField\GridField_URLHandler;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataObjectInterface;
use SilverStripe\ORM\ManyManyList;
use Exception;

/**
 * Allows inline editing of grid field records without having to load a separate
 * edit interface.
 *
 * The form fields used can be configured by setting the value in {@link setDisplayFields()} to one
 * of the following forms:
 *   - A Closure which returns the field instance.
 *   - An array with a `callback` key pointing to a function which returns the field.
 *   - An array with a `field` key->response specifying the field class to use.
 */
class GridFieldEditableColumns extends GridFieldDataColumns implements
    GridField_HTMLProvider,
    GridField_SaveHandler,
    GridField_URLHandler
{

    private static $allowed_actions = array(
        'handleForm'
    );

    /**
     * @var Form[]
     */
    protected $forms = array();

    public function getColumnContent($grid, $record, $col)
    {
        if (!$record->canEdit()) {
            return parent::getColumnContent($grid, $record, $col);
        }

        $fields = $this->getForm($grid, $record)->Fields();

        if (!$this->displayFields) {
            // If setDisplayFields() not used, utilize $summary_fields
            // in a way similar to base class
            $colRelation = explode('.', $col);
            $value = $grid->getDataFieldValue($record, $colRelation[0]);
            $field = $fields->fieldByName($colRelation[0]);
            if (!$field || $field->isReadonly() || $field->isDisabled()) {
                return parent::getColumnContent($grid, $record, $col);
            }

            // Ensure this field is available to edit on the record
            // (ie. Maybe its readonly due to certain circumstances, or removed and not editable)
            $cmsFields = $record->getCMSFields();
            $cmsField = $cmsFields->dataFieldByName($colRelation[0]);
            if (!$cmsField || $cmsField->isReadonly() || $cmsField->isDisabled()) {
                return parent::getColumnContent($grid, $record, $col);
            }
            $field = clone $field;
        } else {
            $value  = $grid->getDataFieldValue($record, $col);
            $rel = (strpos($col, '.') === false); // field references a relation value
            $field = ($rel) ? clone $fields->fieldByName($col) : new ReadonlyField($col);

            if (!$field) {
                throw new Exception("Could not find the field '$col'");
            }
        }

        if (array_key_exists($col, $this->fieldCasting)) {
            $value = $grid->getCastedValue($value, $this->fieldCasting[$col]);
        }

        $value = $this->formatValue($grid, $record, $col, $value);

        $field->setName($this->getFieldName($field->getName(), $grid, $record));
        $field->setValue($value);

        if ($field instanceof HtmlEditorField) {
            return $field->FieldHolder();
        }

        return $field->forTemplate();
    }

    public function getHTMLFragments($grid)
    {
        GridFieldExtensions::include_requirements();
        $grid->addExtraClass('ss-gridfield-editable');
    }

    public function handleSave(GridField $grid, DataObjectInterface $record)
    {
        $list  = $grid->getList();
        $value = $grid->Value();

        if (!isset($value[__CLASS__]) || !is_array($value[__CLASS__])) {
            return;
        }

        /** @var GridFieldOrderableRows $sortable */
        $sortable = $grid->getConfig()->getComponentByType('SilverStripe\\GridFieldExtensions\\GridFieldOrderableRows');

        $form = $this->getForm($grid, $record);

        foreach ($value[__CLASS__] as $id => $fields) {
            if (!is_numeric($id) || !is_array($fields)) {
                continue;
            }

            $item = $list->byID($id);

            if (!$item || !$item->canEdit()) {
                continue;
            }

            $extra = array();

            $form->loadDataFrom($fields, Form::MERGE_CLEAR_MISSING);
            $form->saveInto($item);

            // Check if we are also sorting these records
            if ($sortable) {
                $sortField = $sortable->getSortField();
                $item->setField($sortField, $fields[$sortField]);
            }

            if ($list instanceof ManyManyList) {
                $extra = array_intersect_key($form->getData(), (array) $list->getExtraFields());
            }

            $item->write();
            $list->add($item, $extra);
        }
    }

    public function handleForm(GridField $grid, $request)
    {
        $id   = $request->param('ID');
        $list = $grid->getList();

        if (!ctype_digit($id)) {
            throw new HTTPResponse_Exception(null, 400);
        }

        if (!$record = $list->byID($id)) {
            throw new HTTPResponse_Exception(null, 404);
        }

        $form = $this->getForm($grid, $record);

        foreach ($form->Fields() as $field) {
            $field->setName($this->getFieldName($field->getName(), $grid, $record));
        }

        return $form;
    }

    public function getURLHandlers($grid)
    {
        return array(
            'editable/form/$ID' => 'handleForm'
        );
    }

    /**
     * Gets the field list for a record.
     *
     * @param GridField $grid
     * @param DataObjectInterface $record
     * @return FieldList
     */
    public function getFields(GridField $grid, DataObjectInterface $record)
    {
        $cols   = $this->getDisplayFields($grid);
        $fields = new FieldList();

        $list   = $grid->getList();
        $class  = $list ? $list->dataClass() : null;

        foreach ($cols as $col => $info) {
            $field = null;

            if ($info instanceof Closure) {
                $field = call_user_func($info, $record, $col, $grid);
            } elseif (is_array($info)) {
                if (isset($info['callback'])) {
                    $field = call_user_func($info['callback'], $record, $col, $grid);
                } elseif (isset($info['field'])) {
                    if ($info['field'] == 'SilverStripe\\Forms\\LiteralField') {
                        $field = new $info['field']($col, null);
                    } else {
                        $field = new $info['field']($col);
                    }
                }

                if (!$field instanceof FormField) {
                    throw new Exception(sprintf(
                        'The field for column "%s" is not a valid form field',
                        $col
                    ));
                }
            }

            if (!$field && $list instanceof ManyManyList) {
                $extra = $list->getExtraFields();

                if ($extra && array_key_exists($col, $extra)) {
                    $field = Object::create_from_string($extra[$col], $col)->scaffoldFormField();
                }
            }

            if (!$field) {
                if (!$this->displayFields) {
                    // If setDisplayFields() not used, utilize $summary_fields
                    // in a way similar to base class
                    //
                    // Allows use of 'MyBool.Nice' and 'MyHTML.NoHTML' so that
                    // GridFields not using inline editing still look good or
                    // revert to looking good in cases where the field isn't
                    // available or is readonly
                    //
                    $colRelation = explode('.', $col);
                    if ($class && $obj = singleton($class)->dbObject($colRelation[0])) {
                        $field = $obj->scaffoldFormField();
                    } else {
                        $field = new ReadonlyField($colRelation[0]);
                    }
                } elseif ($class && $obj = singleton($class)->dbObject($col)) {
                    $field = $obj->scaffoldFormField();
                } else {
                    $field = new ReadonlyField($col);
                }
            }

            if (!$field instanceof FormField) {
                throw new Exception(sprintf(
                    'Invalid form field instance for column "%s"',
                    $col
                ));
            }

            // Add CSS class for interactive fields
            if (!($field->isReadOnly() || $field instanceof LiteralField)) {
                $field->addExtraClass('editable-column-field');
            }

            $fields->push($field);
        }

        return $fields;
    }

    /**
     * Gets the form instance for a record.
     *
     * @param GridField $grid
     * @param DataObjectInterface $record
     * @return Form
     */
    public function getForm(GridField $grid, DataObjectInterface $record)
    {
        $fields = $this->getFields($grid, $record);

        $form = new Form($grid, null, $fields, new FieldList());
        $form->loadDataFrom($record);

        $form->setFormAction(Controller::join_links(
            $grid->Link(),
            'editable/form',
            $record->ID
        ));

        return $form;
    }

    protected function getFieldName($name, GridField $grid, DataObjectInterface $record)
    {
        return sprintf(
            '%s[%s][%s][%s]',
            $grid->getName(),
            __CLASS__,
            $record->ID,
            $name
        );
    }
}
