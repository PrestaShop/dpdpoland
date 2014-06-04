<?php
/**
* 2014 DPD Polska Sp. z o.o.
*
* NOTICE OF LICENSE
*
* This source file is subject to the Open Software License (OSL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/osl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* prestashop@dpd.com.pl so we can send you a copy immediately.
*
*  @author    JSC INVERTUS www.invertus.lt <help@invertus.lt>
*  @copyright 2014 DPD Polska Sp. z o.o.
*  @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
*  International Registered Trademark & Property of DPD Polska Sp. z o.o.
*/

if (!defined('_PS_VERSION_'))
	exit;

class DpdPolandPackageListController extends DpdPolandController
{
	const DEFAULT_ORDER_BY 	= 'date_add';
	const DEFAULT_ORDER_WAY = 'desc';
	const FILENAME = 'packageList.controller';

	public static function init($module_instance)
	{
		if (Tools::isSubmit('printManifest'))
		{
			$cookie = Context::getContext()->cookie;
			$isset_package_ids = isset($cookie->DPDPOLAND_PACKAGE_IDS);
			if ($isset_package_ids)
			{
				if (version_compare(_PS_VERSION_, '1.5', '<'))
					$package_ids = unserialize(Context::getContext()->cookie->DPDPOLAND_PACKAGE_IDS);
				else
					$package_ids = Tools::unSerialize(Context::getContext()->cookie->DPDPOLAND_PACKAGE_IDS);

				unset($cookie->DPDPOLAND_PACKAGE_IDS);
				$cookie->write();

				$separated_packages = DpdPolandPackage::separatePackagesBySession($package_ids);
				$international_packages = $separated_packages['INTERNATIONAL'];
				$domestic_packages = $separated_packages['DOMESTIC'];
				$manifest_ids = array();

				if ($international_packages)
					$manifest_ids[] = DpdPolandManifest::getManifestIdByPackageId($international_packages[0]);
				if ($domestic_packages)
					$manifest_ids[] = DpdPolandManifest::getManifestIdByPackageId($domestic_packages[0]);

				require_once(_DPDPOLAND_CLASSES_DIR_.'manifestList.controller.php');
				$manifest_controller = new DpdPolandManifestListController();
				return $manifest_controller->printManifest($manifest_ids);
			}

			if ($package_ids = Tools::getValue('PackagesBox'))
			{
				if (!DpdPolandManifest::validateSenderAddresses($package_ids))
					return $module_instance->outputHTML($module_instance->displayError($module_instance->l('Manifests can not have different sender addresses', self::FILENAME)));

				$separated_packages = DpdPolandPackage::separatePackagesBySession($package_ids);
				$international_packages = $separated_packages['INTERNATIONAL'];
				$domestic_packages = $separated_packages['DOMESTIC'];

				if ($international_packages)
				{
					$manifest = new DpdPolandManifest;
					if (!$manifest->generateMultiple($international_packages))
						return $module_instance->outputHTML($module_instance->displayError(reset(DpdPolandManifest::$errors)));
				}

				if ($domestic_packages)
				{
					$manifest = new DpdPolandManifest;
					if (!$manifest->generateMultiple($domestic_packages))
						return $module_instance->outputHTML($module_instance->displayError(reset(DpdPolandManifest::$errors)));
				}

				$cookie->DPDPOLAND_PACKAGE_IDS = serialize($package_ids);
				Tools::redirectAdmin($module_instance->module_url.'&menu=packages_list');
			}
		}

		if (Tools::isSubmit('printLabelsA4Format'))
		{
			self::printLabels(DpdPolandConfiguration::PRINTOUT_FORMAT_A4);
		}

		if (Tools::isSubmit('printLabelsLabelFormat'))
		{
			self::printLabels(DpdPolandConfiguration::PRINTOUT_FORMAT_LABEL);
		}
	}

	private static function printLabels($printout_format)
	{
		$module_instance = Module::getinstanceByName('dpdpoland');
		
		if ($package_ids = Tools::getValue('PackagesBox'))
		{
			$package = new DpdPolandPackage;

			$separated_packages = DpdPolandPackage::separatePackagesBySession($package_ids);
			$international_packages = $separated_packages['INTERNATIONAL'];
			$domestic_packages = $separated_packages['DOMESTIC'];

			if ($international_packages)
			{
				$package = new DpdPolandPackage;
				if (!$pdf_file_contents_international = $package->generateLabelsForMultiplePackages($international_packages, 'PDF', $printout_format))
					return $module_instance->outputHTML($module_instance->displayError(reset(DpdPolandPackage::$errors)));

				if (file_exists(_PS_MODULE_DIR_.'dpdpoland/international_labels.pdf') && !@unlink(_PS_MODULE_DIR_.'dpdpoland/international_labels.pdf'))
					return $module_instance->outputHTML($module_instance->displayError($module_instance->l('Could not delete old PDF file. Please check module folder permissions', self::FILENAME)));

				$international_pdf = @fopen(_PS_MODULE_DIR_.'dpdpoland/international_labels.pdf', 'w');
				if (!$international_pdf)
					return $module_instance->outputHTML($module_instance->displayError($module_instance->l('Could not create PDF file. Please check module folder permissions', self::FILENAME)));

				fwrite($international_pdf, $pdf_file_contents_international);
				fclose($international_pdf);
			}

			if ($domestic_packages)
			{
				$package = new DpdPolandPackage;
				if (!$pdf_file_contents_domestic = $package->generateLabelsForMultiplePackages($domestic_packages, 'PDF', $printout_format))
					return $module_instance->outputHTML($module_instance->displayError(reset(DpdPolandPackage::$errors)));

				if (file_exists(_PS_MODULE_DIR_.'dpdpoland/domestic_labels.pdf') && !@unlink(_PS_MODULE_DIR_.'dpdpoland/domestic_labels.pdf'))
					return $module_instance->outputHTML($module_instance->displayError($module_instance->l('Could not delete old PDF file. Please check module folder permissions', self::FILENAME)));

				$domestic_pdf = fopen(_PS_MODULE_DIR_.'dpdpoland/domestic_labels.pdf', 'w');
				if (!$domestic_pdf)
					return $module_instance->outputHTML($module_instance->displayError($module_instance->l('Could not create PDF file. Please check module folder permissions', self::FILENAME)));

				fwrite($domestic_pdf, $pdf_file_contents_domestic);
				fclose($domestic_pdf);
			}

			include_once(_PS_MODULE_DIR_.'dpdpoland/PDFMerger/PDFMerger.php');
			$pdf = new PDFMerger;

			if ($international_packages && $domestic_packages)
			{
				if (file_exists(_PS_MODULE_DIR_.'dpdpoland/labels_multisession.pdf') && !@unlink(_PS_MODULE_DIR_.'dpdpoland/labels_multisession.pdf'))
					return $module_instance->outputHTML($module_instance->displayError($module_instance->l('Could not delete old PDF file. Please check module folder permissions', self::FILENAME)));

				$pdf->addPDF(_PS_MODULE_DIR_.'dpdpoland/international_labels.pdf', 'all')
					->addPDF(_PS_MODULE_DIR_.'dpdpoland/domestic_labels.pdf', 'all')
					->merge('file', _PS_MODULE_DIR_.'dpdpoland/labels_multisession.pdf');
			}

			ob_end_clean();
			header('Content-type: application/pdf');
			header('Content-Disposition: attachment; filename="labels_'.time().'.pdf"');
			if ($international_packages && $domestic_packages)
				readfile(_PS_MODULE_DIR_.'dpdpoland/labels_multisession.pdf');
			elseif ($international_packages)
				readfile(_PS_MODULE_DIR_.'dpdpoland/international_labels.pdf');
			elseif ($domestic_packages)
				readfile(_PS_MODULE_DIR_.'dpdpoland/domestic_labels.pdf');
			else
				return $module_instance->outputHTML($module_instance->displayError($module_instance->l('No labels were found', self::FILENAME)));
		}
	}

	public function getList()
	{
		$keys_array = array('date_add', 'id_order', 'package_number', 'count_parcel', 'receiver', 'country', 'postcode', 'city', 'address');
		$this->prepareListData($keys_array, 'Packages', new DpdPolandPackage(), self::DEFAULT_ORDER_BY, self::DEFAULT_ORDER_WAY, 'packages_list');
		$this->context->smarty->assign('order_link', 'index.php?controller=AdminOrders&vieworder&token='.Tools::getAdminTokenLite('AdminOrders'));
		return $this->context->smarty->fetch(_DPDPOLAND_TPL_DIR_.'admin/package_list.tpl');
	}
}