import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { CheckCircle, Wallet, FileText, ArrowRight, Loader2 } from 'lucide-react';
import { api, getPaymentStatus } from '@/lib/api';

const PaymentSuccess = () => {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [loading, setLoading] = useState(true);
  const [smsCredits, setSmsCredits] = useState<number | null>(null);
  const [paymentStatus, setPaymentStatus] = useState<string | null>(null);
  
  const reference = searchParams.get('reference');
  
  useEffect(() => {
    let pollTimer: number | undefined;

    const fetchWalletBalance = async () => {
      try {
        const response = await api.get('/wallet') as unknown as { wallet?: { sms_credits: number }; data?: { wallet?: { sms_credits: number } } };
        const wallet = response.wallet ?? response.data?.wallet;
        if (wallet) {
          setSmsCredits(wallet.sms_credits);
        }
      } catch (error) {
        console.error('Failed to fetch wallet balance:', error);
      }
    };

    const syncPaymentStatus = async (attempt = 0) => {
      if (!reference) {
        await fetchWalletBalance();
        setPaymentStatus('invalid');
        setLoading(false);
        return;
      }

      try {
        const response = await getPaymentStatus(reference);
        const payload = response.data ?? response;
        const resolvedStatus = payload.payment_status || payload.transaction_status || 'pending';
        setPaymentStatus(resolvedStatus);

        if (resolvedStatus === 'pending' && attempt < 6) {
          pollTimer = window.setTimeout(() => {
            void syncPaymentStatus(attempt + 1);
          }, 2000);
          return;
        }

        await fetchWalletBalance();
      } catch {
        await fetchWalletBalance();
      } finally {
        setLoading(false);
      }
    };

    void syncPaymentStatus();

    return () => {
      if (pollTimer) {
        window.clearTimeout(pollTimer);
      }
    };
  }, [reference]);

  const isComplete = paymentStatus === 'completed';
  const isPending = loading || paymentStatus === 'pending';
  
  return (
    <DashboardLayout title={isComplete ? 'Payment Successful' : 'Payment Status'}>
      <div className="flex items-center justify-center min-h-[60vh]">
        <Card className="w-full max-w-lg text-center">
          <CardHeader className="pb-4">
            <div className="mx-auto mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-green-100">
              {isPending ? (
                <Loader2 className="h-12 w-12 text-green-600 animate-spin" />
              ) : (
                <CheckCircle className="h-12 w-12 text-green-600" />
              )}
            </div>
            <CardTitle className="text-2xl">{isComplete ? 'Payment Successful!' : isPending ? 'Payment Processing' : 'Payment not confirmed'}</CardTitle>
            <CardDescription className="text-base">
              {isComplete
                ? 'Your payment was confirmed and your SMS credits are now available.'
                : isPending
                  ? 'We are waiting for the signed PayOS callback to confirm your payment.'
                  : 'This return could not be confirmed as a successful payment. No credits have been promised or added by this page.'}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-6">
            {reference && (
              <div className="bg-muted/50 rounded-lg p-4">
                <p className="text-sm text-muted-foreground">Reference Number</p>
                <p className="font-mono font-semibold">{reference}</p>
              </div>
            )}
            
            {loading ? (
              <div className="flex items-center justify-center py-4">
                <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
              </div>
            ) : smsCredits !== null && (
              <div className="bg-primary/5 rounded-lg p-4 border border-primary/20">
                <p className="text-sm text-muted-foreground">Available SMS Credits</p>
                <p className="text-3xl font-bold text-primary">
                  {smsCredits.toLocaleString()} <span className="text-lg font-normal">credits</span>
                </p>
              </div>
            )}
            
            <div className="flex flex-col gap-3">
              <Button onClick={() => navigate('/wallet')} className="w-full">
                <Wallet className="h-4 w-4 mr-2" />
                Go to Wallet
              </Button>
              
              <Button 
                variant="outline" 
                onClick={() => navigate('/wallet/payments')}
                className="w-full"
              >
                <FileText className="h-4 w-4 mr-2" />
                View Payment History
              </Button>
              
              <Button 
                variant="ghost" 
                onClick={() => navigate('/sms-campaigns')}
                className="w-full"
              >
                Start Sending SMS
                <ArrowRight className="h-4 w-4 ml-2" />
              </Button>
            </div>
            
            <p className="text-sm text-muted-foreground">
              {isComplete
                ? 'A payment confirmation email is sent separately. Check Payment History if it does not arrive.'
                : 'Check Payment History before trying again. A checkout return alone is not proof of payment.'}
            </p>
          </CardContent>
        </Card>
      </div>
    </DashboardLayout>
  );
};

export default PaymentSuccess;
