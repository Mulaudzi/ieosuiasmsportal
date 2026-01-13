import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { CheckCircle, Wallet, FileText, ArrowRight, Loader2 } from 'lucide-react';
import { api } from '@/lib/api';

const PaymentSuccess = () => {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [loading, setLoading] = useState(true);
  const [walletBalance, setWalletBalance] = useState<number | null>(null);
  
  const reference = searchParams.get('reference');
  
  useEffect(() => {
    const fetchWalletBalance = async () => {
      try {
        const response = await api.get('/wallet') as unknown as { wallet: { balance: number } };
        if (response?.wallet) {
          setWalletBalance(response.wallet.balance);
        }
      } catch (error) {
        console.error('Failed to fetch wallet balance:', error);
      } finally {
        setLoading(false);
      }
    };
    
    fetchWalletBalance();
  }, []);
  
  return (
    <DashboardLayout title="Payment Successful">
      <div className="flex items-center justify-center min-h-[60vh]">
        <Card className="w-full max-w-lg text-center">
          <CardHeader className="pb-4">
            <div className="mx-auto mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-green-100">
              <CheckCircle className="h-12 w-12 text-green-600" />
            </div>
            <CardTitle className="text-2xl">Payment Successful!</CardTitle>
            <CardDescription className="text-base">
              Your payment has been processed and credits have been added to your account.
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
            ) : walletBalance !== null && (
              <div className="bg-primary/5 rounded-lg p-4 border border-primary/20">
                <p className="text-sm text-muted-foreground">Current Balance</p>
                <p className="text-3xl font-bold text-primary">
                  {walletBalance.toLocaleString()} <span className="text-lg font-normal">credits</span>
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
                onClick={() => navigate('/sms/campaigns')}
                className="w-full"
              >
                Start Sending SMS
                <ArrowRight className="h-4 w-4 ml-2" />
              </Button>
            </div>
            
            <p className="text-sm text-muted-foreground">
              A confirmation email has been sent to your registered email address.
            </p>
          </CardContent>
        </Card>
      </div>
    </DashboardLayout>
  );
};

export default PaymentSuccess;
