<?php 

namespace EasyTransac\Converters;
use \EasyTransac\Converters\IConverter;

/**
 * Convert boolean vals in String vals (yes, no)
 */
class BooleanToString implements IConverter
{
	/**
	 * {@inheritDoc}
	 * @see \Easytransac\Converters\IConverter::convert()
	 */
	public function convert($value)
	{
		if ($value === true)
			return 'yes';
		else if ($value === false)
			return 'no';
		else 
			return $value;
	}
}

?>