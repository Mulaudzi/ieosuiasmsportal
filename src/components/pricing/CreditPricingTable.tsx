import { creditPricing, rand } from '@/lib/credit-purchases';
import './CreditPricingTable.css';
import { CreditPurchasePolicy } from './CreditPurchasePolicy';

export function CreditPricingTable() {
  return <><table className="credit-pricing-table">
    <caption className="sr-only">SMS credit prices by purchase quantity</caption>
    <thead><tr><th scope="col">Credits purchased</th><th scope="col">Price per credit</th></tr></thead>
    <tbody>{creditPricing.tiers.map(tier => <tr key={tier.min}>
      <th scope="row">{Math.max(creditPricing.minimum, tier.min).toLocaleString('en-ZA')} to {tier.max.toLocaleString('en-ZA')}</th>
      <td>{rand(tier.cents)}</td>
    </tr>)}</tbody>
  </table><CreditPurchasePolicy/></>;
}
