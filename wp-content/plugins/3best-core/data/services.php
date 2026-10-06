<?php
/**
 * Central service configuration.
 *
 * Ported from src/data/services.ts.
 *
 * SLUGS ARE CURATED BY HAND AND ARE THE SEO SURFACE OF THE WHOLE SITE. They are
 * never derived from a display name at runtime:
 *
 *   Plumbing          -> plumber
 *   Electrical        -> electrician
 *   Roofing & Repairs -> roofing
 *
 * This is configuration rather than a table precisely so that renaming a
 * service cannot silently change a published URL. A subcategory id is derived
 * as "{service_id}-{subcategory_slug}", matching the React implementation, so
 * the values submitted by the lead form in Phase 5 are identical.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

return array(

	'categories' => array(
		array(
			'id'          => 'real-estate',
			'name'        => 'Real Estate',
			'slug'        => 'real-estate',
			'description' => 'Buying, selling, leasing and managing residential and commercial property.',
			'icon'        => 'building',
		),
		array(
			'id'          => 'finance',
			'name'        => 'Finance',
			'slug'        => 'finance',
			'description' => 'Tax, accounting, planning and lending support for people and businesses.',
			'icon'        => 'chart',
		),
		array(
			'id'          => 'home-services',
			'name'        => 'Home Services',
			'slug'        => 'home-services',
			'description' => 'Plumbing, HVAC, electrical and roofing work for homes and businesses.',
			'icon'        => 'home',
		),
		array(
			'id'          => 'professional-services',
			'name'        => 'Professional Services',
			'slug'        => 'professional-services',
			'description' => 'Legal, consulting, marketing and IT expertise for growing organisations.',
			'icon'        => 'briefcase',
		),
	),

	'services'   => array(

		/* ---------------------------------------------------------------- */
		/* Real Estate                                                       */
		/* ---------------------------------------------------------------- */
		array(
			'id'                       => 'residential-real-estate',
			'name'                     => 'Residential Real Estate',
			'slug'                     => 'residential-real-estate',
			'category'                 => 'real-estate',
			'description'              => 'Buying, selling and valuing homes and residential property.',
			'service_term'             => 'residential real estate',
			'professional_plural'      => 'Residential Real Estate Professionals',
			'professional_plural_lower' => 'residential real estate professionals',
			'subcategories'            => array(
				array( 'Home Buying', 'home-buying', 'Finding and purchasing a primary or second home.' ),
				array( 'Home Selling', 'home-selling', 'Listing, marketing and selling a home.' ),
				array( 'Real Estate Agent', 'real-estate-agent', 'Representation for a residential purchase or sale.' ),
				array( 'Property Valuation', 'property-valuation', 'Understanding what a property is worth today.' ),
			),
		),
		array(
			'id'                       => 'commercial-real-estate',
			'name'                     => 'Commercial Real Estate',
			'slug'                     => 'commercial-real-estate',
			'category'                 => 'real-estate',
			'description'              => 'Sales, leasing and investment support for commercial property.',
			'service_term'             => 'commercial real estate',
			'professional_plural'      => 'Commercial Real Estate Professionals',
			'professional_plural_lower' => 'commercial real estate professionals',
			'subcategories'            => array(
				array( 'Commercial Property Sales', 'commercial-property-sales', 'Buying or selling office, retail and industrial space.' ),
				array( 'Commercial Leasing', 'commercial-leasing', 'Tenant and landlord representation for leases.' ),
				array( 'Property Valuation', 'property-valuation', 'Valuations and appraisals for commercial property.' ),
				array( 'Investment Properties', 'investment-properties', 'Income property acquisition and analysis.' ),
			),
		),
		array(
			'id'                       => 'property-management',
			'name'                     => 'Property Management',
			'slug'                     => 'property-management',
			'category'                 => 'real-estate',
			'description'              => 'Day-to-day management of residential and commercial rentals.',
			'service_term'             => 'property management',
			'professional_plural'      => 'Property Managers',
			'professional_plural_lower' => 'property managers',
			'subcategories'            => array(
				array( 'Residential Property Management', 'residential-property-management', 'Full management for single homes and multi-family units.' ),
				array( 'Commercial Property Management', 'commercial-property-management', 'Management for office, retail and mixed-use property.' ),
				array( 'Tenant Management', 'tenant-management', 'Screening, leasing and tenant relations.' ),
				array( 'Rental Management', 'rental-management', 'Rent collection, maintenance and turnovers.' ),
			),
		),
		array(
			'id'                       => 'real-estate-agents-brokers',
			'name'                     => 'Real Estate Agents & Brokers',
			'slug'                     => 'real-estate-agent',
			'category'                 => 'real-estate',
			'description'              => 'Agent and broker representation for buyers and sellers.',
			'service_term'             => 'real estate',
			'professional_plural'      => 'Real Estate Agents',
			'professional_plural_lower' => 'real estate agents',
			'subcategories'            => array(
				array( 'Buyer Representation', 'buyer-representation', "An agent working on the buyer's side of a purchase." ),
				array( 'Seller Representation', 'seller-representation', 'Listing agents and seller-side representation.' ),
				array( 'Listing Services', 'listing-services', 'Pricing, marketing and listing support.' ),
				array( 'Broker Consultation', 'broker-consultation', 'A broker-level conversation about your options.' ),
			),
		),

		/* ---------------------------------------------------------------- */
		/* Finance                                                           */
		/* ---------------------------------------------------------------- */
		array(
			'id'                       => 'tax-preparation',
			'name'                     => 'Tax Preparation',
			'slug'                     => 'tax-preparer',
			'category'                 => 'finance',
			'description'              => 'Individual and business tax preparation, planning and filing.',
			'service_term'             => 'tax preparation',
			'professional_plural'      => 'Tax Preparers',
			'professional_plural_lower' => 'tax preparers',
			'subcategories'            => array(
				array( 'Individual Tax Preparation', 'individual-tax-preparation', 'Personal federal and state returns.' ),
				array( 'Business Tax Preparation', 'business-tax-preparation', 'Returns for LLCs, S-corps, partnerships and corporations.' ),
				array( 'Tax Planning', 'tax-planning', 'Planning ahead to manage what you owe.' ),
				array( 'Tax Filing', 'tax-filing', 'Filing, extensions and amended returns.' ),
			),
		),
		array(
			'id'                       => 'accounting-bookkeeping',
			'name'                     => 'Accounting & Bookkeeping',
			'slug'                     => 'accountant',
			'category'                 => 'finance',
			'description'              => 'Bookkeeping, payroll and reporting for individuals and businesses.',
			'service_term'             => 'accounting',
			'professional_plural'      => 'Accountants',
			'professional_plural_lower' => 'accountants',
			'subcategories'            => array(
				array( 'Bookkeeping', 'bookkeeping', 'Monthly books, reconciliations and clean-up work.' ),
				array( 'Payroll Services', 'payroll-services', 'Payroll processing, filings and compliance.' ),
				array( 'Business Accounting', 'business-accounting', 'Ongoing accounting support for your business.' ),
				array( 'Financial Reporting', 'financial-reporting', 'Statements and reporting for owners and lenders.' ),
			),
		),
		array(
			'id'                       => 'financial-planning',
			'name'                     => 'Financial Planning',
			'slug'                     => 'financial-advisor',
			'category'                 => 'finance',
			'description'              => 'Retirement, investment and long-term wealth planning.',
			'service_term'             => 'financial planning',
			'professional_plural'      => 'Financial Advisors',
			'professional_plural_lower' => 'financial advisors',
			'subcategories'            => array(
				array( 'Retirement Planning', 'retirement-planning', '401(k), IRA and long-term retirement strategy.' ),
				array( 'Investment Planning', 'investment-planning', 'Building and managing an investment portfolio.' ),
				array( 'Wealth Management', 'wealth-management', 'Coordinated planning for larger portfolios.' ),
				array( 'Financial Planning', 'financial-planning', 'A full plan covering goals, savings and risk.' ),
			),
		),
		array(
			'id'                       => 'mortgage-lending',
			'name'                     => 'Mortgage & Lending',
			'slug'                     => 'mortgage-lender',
			'category'                 => 'finance',
			'description'              => 'Home loans, refinancing and government-backed mortgage programs.',
			'service_term'             => 'mortgage',
			'professional_plural'      => 'Mortgage Professionals',
			'professional_plural_lower' => 'mortgage professionals',
			'subcategories'            => array(
				array( 'Home Loans', 'home-loans', 'Purchase mortgages for first-time and repeat buyers.' ),
				array( 'Mortgage Refinancing', 'mortgage-refinancing', 'Lowering a rate, changing a term or cashing out.' ),
				array( 'FHA Loans', 'fha-loans', 'Federally insured loans with lower down payments.' ),
				array( 'VA Loans', 'va-loans', 'Mortgage options for veterans and service members.' ),
			),
		),

		/* ---------------------------------------------------------------- */
		/* Home Services                                                     */
		/* ---------------------------------------------------------------- */
		array(
			'id'                       => 'plumbing',
			'name'                     => 'Plumbing',
			'slug'                     => 'plumber',
			'category'                 => 'home-services',
			'description'              => 'Repairs, installations and emergency work for pipes, drains and water heaters.',
			'service_term'             => 'plumbing',
			'professional_plural'      => 'Plumbers',
			'professional_plural_lower' => 'plumbers',
			'subcategories'            => array(
				array( 'Emergency Plumbing', 'emergency-plumbing', 'Burst pipes, active leaks and after-hours problems.' ),
				array( 'Drain Cleaning', 'drain-cleaning', 'Slow or blocked drains, sinks, tubs and main lines.' ),
				array( 'Water Heater Repair', 'water-heater-repair', 'Repair, replacement and installation of water heaters.' ),
				array( 'Sewer & Pipe Repair', 'sewer-pipe-repair', 'Sewer lines, repiping and underground pipe work.' ),
			),
		),
		array(
			'id'                       => 'hvac',
			'name'                     => 'HVAC',
			'slug'                     => 'hvac',
			'category'                 => 'home-services',
			'description'              => 'Heating, cooling and ventilation repair, installation and maintenance.',
			'service_term'             => 'HVAC',
			'professional_plural'      => 'HVAC Professionals',
			'professional_plural_lower' => 'HVAC professionals',
			'subcategories'            => array(
				array( 'AC Repair', 'ac-repair', 'Cooling that is weak, noisy or not running at all.' ),
				array( 'Heating Repair', 'heating-repair', 'Furnace, boiler and heat pump troubleshooting.' ),
				array( 'HVAC Installation', 'hvac-installation', 'New systems, replacements and ductwork.' ),
				array( 'HVAC Maintenance', 'hvac-maintenance', 'Seasonal tune-ups and preventative service plans.' ),
			),
		),
		array(
			'id'                       => 'electrical',
			'name'                     => 'Electrical',
			'slug'                     => 'electrician',
			'category'                 => 'home-services',
			'description'              => 'Wiring, lighting, panel work and electrical repairs.',
			'service_term'             => 'electrical',
			'professional_plural'      => 'Electricians',
			'professional_plural_lower' => 'electricians',
			'subcategories'            => array(
				array( 'Electrical Repair', 'electrical-repair', 'Outlets, switches, breakers and troubleshooting.' ),
				array( 'Electrical Installation', 'electrical-installation', 'New circuits, fixtures, outlets and appliances.' ),
				array( 'Lighting Services', 'lighting-services', 'Indoor, outdoor and landscape lighting.' ),
				array( 'Panel & Wiring Services', 'panel-wiring-services', 'Panel upgrades, rewiring and service capacity.' ),
			),
		),
		array(
			'id'                       => 'roofing',
			'name'                     => 'Roofing & Repairs',
			'slug'                     => 'roofing',
			'category'                 => 'home-services',
			'description'              => 'Roof repair, replacement, inspection and storm damage work.',
			'service_term'             => 'roofing',
			'professional_plural'      => 'Roofing Professionals',
			'professional_plural_lower' => 'roofing professionals',
			'subcategories'            => array(
				array( 'Roof Repair', 'roof-repair', 'Leaks, missing shingles and flashing problems.' ),
				array( 'Roof Replacement', 'roof-replacement', 'Full tear-off and new roof installation.' ),
				array( 'Roof Inspection', 'roof-inspection', 'Condition reports for sales, insurance and maintenance.' ),
				array( 'Storm Damage Repair', 'storm-damage-repair', 'Wind, hail and storm-related roof damage.' ),
			),
		),

		/* ---------------------------------------------------------------- */
		/* Professional Services                                             */
		/* ---------------------------------------------------------------- */
		array(
			'id'                       => 'legal-services',
			'name'                     => 'Legal Services',
			'slug'                     => 'lawyer',
			'category'                 => 'professional-services',
			'description'              => 'Business, family, real estate and estate planning matters.',
			'service_term'             => 'legal',
			'professional_plural'      => 'Lawyers',
			'professional_plural_lower' => 'lawyers',
			'subcategories'            => array(
				array( 'Business Law', 'business-law', 'Contracts, formation and commercial matters.' ),
				array( 'Family Law', 'family-law', 'Divorce, custody and family-related matters.' ),
				array( 'Real Estate Law', 'real-estate-law', 'Closings, title issues and property disputes.' ),
				array( 'Estate Planning', 'estate-planning', 'Wills, trusts and estate administration.' ),
			),
		),
		array(
			'id'                       => 'business-consulting',
			'name'                     => 'Business Consulting',
			'slug'                     => 'business-consultant',
			'category'                 => 'professional-services',
			'description'              => 'Strategy, operations and management support for growing businesses.',
			'service_term'             => 'business consulting',
			'professional_plural'      => 'Business Consultants',
			'professional_plural_lower' => 'business consultants',
			'subcategories'            => array(
				array( 'Business Strategy', 'business-strategy', 'Growth planning, positioning and market entry.' ),
				array( 'Operations Consulting', 'operations-consulting', 'Process, cost and efficiency improvements.' ),
				array( 'Startup Consulting', 'startup-consulting', 'Early-stage planning, launch and fundraising support.' ),
				array( 'Management Consulting', 'management-consulting', 'Leadership, structure and organisational change.' ),
			),
		),
		array(
			'id'                       => 'marketing-advertising',
			'name'                     => 'Marketing & Advertising',
			'slug'                     => 'marketing-agency',
			'category'                 => 'professional-services',
			'description'              => 'Digital marketing, SEO, social and content programs.',
			'service_term'             => 'marketing',
			'professional_plural'      => 'Marketing Professionals',
			'professional_plural_lower' => 'marketing professionals',
			'subcategories'            => array(
				array( 'Digital Marketing', 'digital-marketing', 'Paid media, email and full-funnel campaigns.' ),
				array( 'SEO Services', 'seo-services', 'Search visibility, technical SEO and content.' ),
				array( 'Social Media Marketing', 'social-media-marketing', 'Organic and paid social programs.' ),
				array( 'Content Marketing', 'content-marketing', 'Content strategy, writing and production.' ),
			),
		),
		array(
			'id'                       => 'it-services',
			'name'                     => 'IT Services',
			'slug'                     => 'it-services',
			'category'                 => 'professional-services',
			'description'              => 'IT support, managed services, security and cloud.',
			'service_term'             => 'IT',
			'professional_plural'      => 'IT Professionals',
			'professional_plural_lower' => 'IT professionals',
			'subcategories'            => array(
				array( 'IT Support', 'it-support', 'Helpdesk and day-to-day technical support.' ),
				array( 'Managed IT Services', 'managed-it-services', 'Ongoing management of your systems and devices.' ),
				array( 'Cybersecurity', 'cybersecurity', 'Security assessments, monitoring and response.' ),
				array( 'Cloud Services', 'cloud-services', 'Migration, hosting and cloud infrastructure.' ),
			),
		),
	),

	/**
	 * Display order for categories.
	 */
	'order'      => array( 'real-estate', 'finance', 'home-services', 'professional-services' ),
);
